#!/usr/bin/env python3
"""Smoke test: every page of the game answers, for a player and for an admin, with no PHP error.

    python3 tools/dev/smoke.py [--port 8765] [--only attack.php,bank.php] [--json out.json]

Needs a game installed by tools/dev/setup.php (config.php in the root). Starts PHP's own web server, logs in as
player1 and as admin, asks for every top-level page, and fails if any answers with a server error, a PHP warning or
notice, the game's "Critical Error" page, or a redirect back to the login page. Uses only Python's standard library.
"""
import argparse
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
# files that are parts of pages, not pages; or that must not be called in a test (installer, payment callbacks, logout)
NOT_PAGES = {"config.php", "globals.php", "sglobals.php", "globals_nonauth.php", "global_func.php", "header.php", "mainmenu.php", "smenu.php",
             "installer.php", "installer_head.php", "installer_foot.php", "ipn_donator.php", "ipn_wp.php", "logout.php", "authenticate.php",
             "captcha_verify.php", "macro2.php"}
BAD = [r"Critical Error", r"<b>(Fatal error|Warning|Notice|Deprecated|Parse error)</b>", r"Uncaught \w+", r"Stack trace:", r"QUERY ERROR", r"mysqli_sql_exception"]


class Session:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.open = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect()).open

    def get(self, path, data=None):
        req = urllib.request.Request(self.base + path, data=urllib.parse.urlencode(data).encode() if data else None)
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


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def pages():
    return sorted(f for f in os.listdir(ROOT) if f.endswith(".php") and f not in NOT_PAGES)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=8765)
    ap.add_argument("--only", default="")
    ap.add_argument("--json", default="")
    a = ap.parse_args()
    if not os.path.exists(os.path.join(ROOT, "config.php")):
        sys.exit("No config.php: run tools/dev/setup.php first.")
    log = open(os.path.join(ROOT, ".smoke-server.log"), "w")
    server = subprocess.Popen(["php", "-d", "display_errors=1", "-d", "error_reporting=E_ALL", "-d", "log_errors=1", "-S", f"127.0.0.1:{a.port}", "-t", ROOT],
                              cwd=ROOT, stdout=log, stderr=subprocess.STDOUT)
    base = f"http://127.0.0.1:{a.port}"
    try:
        for _ in range(50):
            try:
                urllib.request.urlopen(base + "/login.php", timeout=2)
                break
            except Exception:
                time.sleep(0.2)
        want = [p for p in pages() if not a.only or p in a.only.split(",")]
        results, failed = [], 0
        for who, password in (("player1", "playerpass1"), ("admin", "adminpass1")):
            s = Session(base)
            ok, said = s.login(who, password)
            if not ok:
                print(f"FAIL  {who} could not log in: {said!r}")
                failed += 1
                continue
            for p in want:
                if who == "player1" and p.startswith("staff") and p not in ("stafflist.php",):
                    continue                      # staff pages are for the admin run
                code, headers, body = s.get("/" + p)
                loc = headers.get("Location") or ""
                why = ""
                if code >= 500:
                    why = f"HTTP {code}"
                elif code in (301, 302) and "login.php" in loc:
                    why = "sent back to the login page"
                else:
                    for pat in BAD:
                        m = re.search(pat, body)
                        if m:
                            i = max(0, m.start() - 60)
                            why = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", body[i:m.end() + 220])).strip()
                            break
                results.append({"who": who, "page": p, "status": code, "ok": not why, "why": why})
                if why:
                    failed += 1
                    print(f"FAIL  {who:8} {p:26} {why[:230]}")
        good = sum(1 for r in results if r["ok"])
        print(f"\n{good} of {len(results)} page loads clean ({len(want)} pages, as a player and as an admin)." + ("" if not failed else f" {failed} FAILED."))
        if a.json:
            json.dump(results, open(a.json, "w"), indent=1)
        return 1 if failed else 0
    finally:
        server.terminate()
        log.close()


if __name__ == "__main__":
    sys.exit(main())
