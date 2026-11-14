"""Shared by the development tests: start the game on PHP's own server, log in, ask for pages, read the database."""
import http.cookiejar
import os
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Session:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.open = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect()).open

    def get(self, path, data=None):
        req = urllib.request.Request(self.base + path, data=urllib.parse.urlencode(data).encode() if data is not None else None)
        try:
            r = self.open(req, timeout=30)
            return r.status, r.headers, r.read().decode("latin-1")
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read().decode("latin-1")

    def login(self, user, password):
        _, _, page = self.get("/login.php")
        verf = re.search(r"name='verf' value='([^']+)'", page)
        code, headers, body = self.get("/authenticate.php", {"username": user, "password": password, "verf": verf.group(1) if verf else "", "save": "OFF"})
        return code in (301, 302) and "loggedin.php" in (headers.get("Location") or ""), body[:200]

    def form(self, path, post, token_from=None):
        """post a form, first fetching the page it is on to pick up its anti-forgery token (the field named verf)"""
        _, _, page = self.get(token_from or path.split("?")[0])
        tokens = []
        for form in re.findall(r"<form\b.*?</form>", page, re.S | re.I):
            action = re.search(r"action=['\"]([^'\"]*)['\"]", form)
            token = re.search(r"name=['\"]verf['\"] value=['\"]([^'\"]+)['\"]", form)
            if token and action and action.group(1).replace("&amp;", "&") == path.lstrip("/"):
                tokens.insert(0, token.group(1))          # the form that posts to this address
            elif token:
                tokens.append(token.group(1))
        return self.get(path, dict(post, **({"verf": tokens[0]} if tokens else {}))), tokens


class Server:
    def __init__(self, port):
        self.port, self.base = port, f"http://127.0.0.1:{port}"

    def __enter__(self):
        self.log = open(os.path.join(ROOT, ".smoke-server.log"), "w")
        env = dict(os.environ, PHP_CLI_SERVER_WORKERS="8")        # several requests at once, as a real web server takes them
        self.proc = subprocess.Popen(["php", "-d", "display_errors=1", "-d", "error_reporting=E_ALL", "-d", "log_errors=1", "-S", f"127.0.0.1:{self.port}", "-t", ROOT],
                                     cwd=ROOT, stdout=self.log, stderr=subprocess.STDOUT, env=env)
        for _ in range(50):
            try:
                urllib.request.urlopen(self.base + "/login.php", timeout=2)
                break
            except Exception:
                time.sleep(0.2)
        return self

    def __exit__(self, *a):
        self.proc.terminate()
        self.log.close()


def config():
    text = open(os.path.join(ROOT, "config.php"), encoding="latin-1").read()
    return {k: re.search(rf"'{k}' => '([^']*)'", text).group(1) for k in ("hostname", "username", "password", "database")}


def sql(query):
    """run one statement against the game's database and return its rows as lists of strings"""
    c = config()
    import shutil
    client = shutil.which("mariadb") or shutil.which("mysql")
    out = subprocess.run([client, "-h", c["hostname"], "-u", c["username"], f"-p{c['password']}", c["database"], "-N", "-B", "-e", query],
                         capture_output=True, text=True)
    if out.returncode:
        raise RuntimeError(out.stderr.strip())
    return [line.split("\t") for line in out.stdout.splitlines()]
