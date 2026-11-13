#!/usr/bin/env python3
"""Flow tests: things a player does, end to end, checked against the database.

    python3 tests/flows.py

Needs a fresh game from tools/dev/setup.php. Each flow sets the player's balances, uses the real pages through a real
login, and checks both what the page said and what the database holds afterwards. The "at once" checks log the same
player in on several devices and send the same request from all of them together: money must never be made or lost.
"""
import os
import sys
import threading

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "tools", "dev"))
from gamelib import Server, Session, sql  # noqa: E402

failed = 0


def check(ok, what, detail=""):
    global failed
    print(("ok    " if ok else "FAIL  ") + what + ("" if ok or detail == "" else f"\n      {str(detail)[:400]}"))
    failed += 0 if ok else 1


def balances(uid=2):
    r = sql(f"SELECT money, bankmoney, crystals, cybermoney FROM users WHERE userid = {uid}")[0]
    return dict(zip(("money", "bank", "crystals", "cyber"), map(int, r)))


def at_once(base, user, password, n, request):
    """n logins of the same player, all sending request(session) at the same moment; returns each one's answer"""
    sessions = []
    for _ in range(n):
        s = Session(base)
        s.login(user, password)
        sessions.append(s)
    prepared = [request(s, prepare=True) for s in sessions]
    gate, out = threading.Barrier(n), []

    def go(s, p):
        gate.wait()
        out.append(request(s, prepared=p))
    threads = [threading.Thread(target=go, args=(s, p)) for s, p in zip(sessions, prepared)]
    [t.start() for t in threads]
    [t.join() for t in threads]
    return out


def bank(srv):
    s = Session(srv.base)
    check(s.login("player1", "playerpass1")[0], "player1 logs in")
    sql("UPDATE users SET money = 60000, bankmoney = -1 WHERE userid = 2")
    _, _, page = s.get("/bank.php")
    check("Open a bank account today" in page and "name='verf'" in page, "with no account, the bank offers one, on a form with a token")
    code, _, body = s.get("/bank.php?buy")
    check(balances()["bank"] == -1 and balances()["money"] == 60000, "following the old ?buy link changes nothing: buying needs the form")
    (_, _, body), _ = s.form("/bank.php?action=buy", {})
    check("you bought a bank account" in body and balances() == dict(balances(), money=10000, bank=0), "buying an account takes $50,000 and opens it", balances())
    (_, _, body), _ = s.form("/bank.php?action=deposit", {"deposit": "10000"})
    check("is added to your account" in body and balances()["money"] == 0 and balances()["bank"] == 8500, "depositing $10,000 costs the 15% fee: $8,500 reaches the bank", balances())
    sql("UPDATE users SET money = 100000 WHERE userid = 2")
    s.form("/bank.php?action=deposit", {"deposit": "100000"})
    check(balances()["bank"] == 8500 + 97000 and balances()["money"] == 0, "the fee is capped at $3,000 on a large deposit", balances())
    (_, _, body), _ = s.form("/bank.php?action=deposit", {"deposit": "1"})
    check("do not have enough money" in body and balances()["bank"] == 105500, "depositing more than you hold is refused and changes nothing")
    (_, _, body), _ = s.form("/bank.php?action=withdraw", {"withdraw": "5500"})
    check("hands it over" in body and balances()["money"] == 5500 and balances()["bank"] == 100000, "withdrawing has no fee", balances())
    (_, _, body), _ = s.form("/bank.php?action=withdraw", {"withdraw": "100001"})
    check("do not have enough banked money" in body and balances()["bank"] == 100000, "withdrawing more than the balance is refused")
    for bad in ("-500", "abc", "", "1e9", "5 OR 1=1"):
        s.form("/bank.php?action=withdraw", {"withdraw": bad})
    b = balances()
    check(b["money"] + b["bank"] == 105500 and b["money"] >= 0 and b["bank"] >= 0, "odd amounts (negative, words, empty, SQL) never create or destroy money", b)
    before = balances()
    code, _, body = s.get("/bank.php?action=withdraw", {"withdraw": "1000"})
    check(balances() == before and "hands it over" not in body, "a withdrawal sent without the form's token does nothing")
    code, _, body = s.get("/bank.php?action=withdraw", {"withdraw": "1000", "verf": "0" * 32})
    check(balances() == before, "a withdrawal with a made-up token does nothing")

    # the v2 cheat: one balance, withdrawn from ten logins at the same moment
    sql("UPDATE users SET money = 0, bankmoney = 1000 WHERE userid = 2")

    def withdraw(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get("/bank.php")
            import re
            return re.search(r"withdraw' method='post'><input type='hidden' name='verf' value='([^']+)'", page).group(1)
        _, _, body = sess.get("/bank.php?action=withdraw", {"withdraw": "1000", "verf": prepared})
        return "hands it over" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, withdraw)
    b = balances()
    check(sum(out) == 1 and b["money"] == 1000 and b["bank"] == 0, "ten logins withdrawing the same $1,000 at once: exactly one is paid, and the bank never goes below zero", (sum(out), b))

    sql("UPDATE users SET money = 1000, bankmoney = 0 WHERE userid = 2")

    def deposit(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get("/bank.php")
            import re
            return re.search(r"deposit' method='post'><input type='hidden' name='verf' value='([^']+)'", page).group(1)
        _, _, body = sess.get("/bank.php?action=deposit", {"deposit": "1000", "verf": prepared})
        return "is added to your account" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, deposit)
    b = balances()
    check(sum(out) == 1 and b["money"] == 0 and b["bank"] == 850, "ten logins depositing the same $1,000 at once: it is banked once", (sum(out), b))


def main():
    with Server(8770) as srv:
        bank(srv)
    print("\nAll flows passed." if not failed else f"\n{failed} flow check(s) FAILED")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
