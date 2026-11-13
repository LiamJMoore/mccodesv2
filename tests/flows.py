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


def cyberbank(srv):
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    sql("UPDATE users SET money = 12000000, cybermoney = -1 WHERE userid = 2")
    s.get("/cyberbank.php?buy")
    check(balances()["cyber"] == -1, "the Cyber Bank's old ?buy link changes nothing by itself")
    (_, _, body), _ = s.form("/cyberbank.php?action=buy", {})
    check("you bought a bank account" in body and balances()["money"] == 2000000 and balances()["cyber"] == 0, "opening a Cyber Bank account takes $10,000,000", balances())
    (_, _, body), _ = s.form("/cyberbank.php?action=deposit", {"deposit": "2000000"})
    check(balances()["money"] == 0 and balances()["cyber"] == 1700000, "a $2,000,000 deposit pays the 15% fee: $1,700,000 is banked", balances())
    (_, _, body), _ = s.form("/cyberbank.php?action=withdraw", {"withdraw": "1000000"})
    b = balances()
    check("hands it over" in body and b["cyber"] == 700000 and b["money"] == 925000, "a $1,000,000 withdrawal leaves the bank whole and pays out less the 7.5% fee ($925,000)", b)
    (_, _, body), _ = s.form("/cyberbank.php?action=withdraw", {"withdraw": "700001"})
    check("do not have enough banked money" in body and balances()["cyber"] == 700000, "withdrawing more than the Cyber Bank holds is refused")
    sql("UPDATE users SET money = 0, cybermoney = 1000000 WHERE userid = 2")
    import re

    def withdraw(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get("/cyberbank.php")
            return re.search(r"withdraw' method='post'><input type='hidden' name='verf' value='([^']+)'", page).group(1)
        _, _, body = sess.get("/cyberbank.php?action=withdraw", {"withdraw": "1000000", "verf": prepared})
        return "hands it over" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, withdraw)
    b = balances()
    check(sum(out) == 1 and b["cyber"] == 0 and b["money"] == 925000, "ten logins emptying the Cyber Bank at once: one is paid", (sum(out), b))


def sending(srv):
    """player1 (id 2) sends to player2 (id 3): cash, crystals, bank to bank, cyber to cyber"""
    import re
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    cases = [("sendcash", "money", "money", "money"), ("sendcrys", "crystals", "crystals", "crystals"), ("sendbank", "xfer", "bankmoney", "bank"), ("sendcyber", "xfer", "cybermoney", "cyber")]
    for page, field, column, key in cases:
        sql(f"UPDATE users SET money = 0, crystals = 0, bankmoney = 0, cybermoney = 0 WHERE userid IN (2, 3)")
        sql(f"UPDATE users SET {column} = 500 WHERE userid = 2")
        (_, _, body), tokens = s.form(f"/{page}.php?ID=3", {field: "200"}, token_from=f"/{page}.php?ID=3")
        check(balances(2)[key] == 300 and balances(3)[key] == 200, f"{page}: sending 200 takes it from the sender and gives it to the receiver", (balances(2), balances(3), body[-200:]))
        (_, _, body), _ = s.form(f"/{page}.php?ID=3", {field: "301"}, token_from=f"/{page}.php?ID=3")
        check(balances(2)[key] == 300 and balances(3)[key] == 200 and "Not enough" in body, f"{page}: sending more than you hold is refused and nothing moves", body[-160:])
        (_, _, body), _ = s.form(f"/{page}.php?ID=2", {field: "10"}, token_from=f"/{page}.php?ID=2")
        (_, _, body2), _ = s.form(f"/{page}.php?ID=999999", {field: "10"}, token_from=f"/{page}.php?ID=999999")
        check(balances(2)[key] == 300 and "exist" in body2, f"{page}: you cannot send to yourself or to a player who does not exist, and nothing is lost")

        def send(sess, prepare=False, prepared=None, page=page, field=field):
            if prepare:
                _, _, html = sess.get(f"/{page}.php?ID=3")
                return re.search(r"name='verf' value='([^']+)'", html).group(1)
            _, _, body = sess.get(f"/{page}.php?ID=3", {field: "300", "verf": prepared})
            return "You sent" in body or "sent" in body.lower() and "not enough" not in body.lower()
        out = at_once(srv.base, "player1", "playerpass1", 8, send)
        a, b = balances(2), balances(3)
        check(a[key] == 0 and b[key] == 500 and a[key] + b[key] == 500, f"{page}: eight logins sending the same 300 at once: it arrives once, and the total between the two players is unchanged", (sum(out), a[key], b[key]))
    sql("UPDATE users SET bankmoney = 500 WHERE userid = 2")
    sql("UPDATE users SET bankmoney = -1 WHERE userid = 3")
    (_, _, body), _ = s.form("/sendbank.php?ID=3", {"xfer": "100"}, token_from="/sendbank.php?ID=3")
    check(balances(2)["bank"] == 500 and balances(3)["bank"] == -1, "sendbank: money is not sent into a bank account the receiver has not opened", body[-200:])


def inv(uid, item):
    r = sql(f"SELECT COALESCE(SUM(inv_qty), 0) FROM inventory WHERE inv_userid = {uid} AND inv_itemid = {item}")
    return int(r[0][0])


def shop(srv):
    import re
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    sql("DELETE FROM inventory")
    sql("UPDATE users SET money = 2500, location = 1 WHERE userid = 2")
    (_, _, body), tokens = s.form("/itembuy.php?ID=2", {"qty": "2"}, token_from="/shops.php?shop=1")
    check("You bought 2 Baseball Bat" in body and balances()["money"] == 500 and inv(2, 2) == 2, "the shop: two bats at $1,000 take $2,000 and put two bats in the inventory", (body[-200:], balances(), tokens[:1]))
    (_, _, body), _ = s.form("/itembuy.php?ID=2", {"qty": "1"}, token_from="/shops.php?shop=1")
    check("enough money" in body and balances()["money"] == 500 and inv(2, 2) == 2, "buying what you cannot afford is refused: no money taken, no item given")
    code, _, body = s.get("/itembuy.php?ID=1", {"qty": "1"})
    check(balances()["money"] == 500 and inv(2, 1) == 0, "a purchase sent without the shop page's token buys nothing")
    (_, _, body), _ = s.form("/itembuy.php?ID=4", {"qty": "1"}, token_from="/shops.php?shop=1")
    (_, _, body2), _ = s.form("/itembuy.php?ID=5", {"qty": "1"}, token_from="/shops.php?shop=1")
    check(balances()["money"] == 500 and "other cities" in body and "can\'t be bought" in body2.replace("&#039;", "'").replace("'", "\'"), "an item in another city's shop, or one that is not for sale, cannot be bought and costs nothing", (body[-120:], body2[-120:]))
    sql("UPDATE users SET money = 1000 WHERE userid = 2")

    def buy(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get("/shops.php?shop=1")
            return re.search(r"name='verf' value='([^']+)'", page).group(1)
        _, _, body = sess.get("/itembuy.php?ID=2", {"qty": "1", "verf": prepared})
        return "You bought" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, buy)
    check(sum(out) == 1 and balances()["money"] == 0 and inv(2, 2) == 3, "ten logins buying a $1,000 bat with $1,000 at once: one bat is bought and the money never goes below zero", (sum(out), balances()["money"], inv(2, 2)))

    # selling back
    row = sql("SELECT inv_id FROM inventory WHERE inv_userid = 2 AND inv_itemid = 2")[0][0]
    (_, _, body), _ = s.form(f"/itemsell.php?ID={row}", {"qty": "1"}, token_from=f"/itemsell.php?ID={row}")
    check("You sold 1" in body and balances()["money"] == 500 and inv(2, 2) == 2, "selling one bat back pays its $500 sell price and removes one", (body[-150:], balances()["money"], inv(2, 2)))
    (_, _, body), _ = s.form(f"/itemsell.php?ID={row}", {"qty": "3"}, token_from=f"/itemsell.php?ID={row}")
    check("more than you have" in body and balances()["money"] == 500 and inv(2, 2) == 2, "selling more than you hold is refused")

    def sell(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get(f"/itemsell.php?ID={row}")
            return re.search(r"name='verf' value='([^']+)'", page).group(1)
        _, _, body = sess.get(f"/itemsell.php?ID={row}", {"qty": "2", "verf": prepared})
        return "You sold" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, sell)
    check(sum(out) == 1 and balances()["money"] == 1500 and inv(2, 2) == 0, "ten logins selling the same two bats at once: they are sold once", (sum(out), balances()["money"], inv(2, 2)))


def markets(srv):
    import re
    seller, buyer = Session(srv.base), Session(srv.base)
    seller.login("player1", "playerpass1")
    buyer.login("player2", "playerpass2")
    sql("DELETE FROM inventory; DELETE FROM itemmarket; DELETE FROM crystalmarket")
    sql("UPDATE users SET money = 0, crystals = 0 WHERE userid IN (2, 3)")
    sql("INSERT INTO inventory (inv_itemid, inv_userid, inv_qty) VALUES (2, 2, 3)")
    row = sql("SELECT inv_id FROM inventory WHERE inv_userid = 2")[0][0]
    (_, _, body), _ = seller.form(f"/imadd.php?ID={row}", {"price": "400", "QTY": "3", "currency": "money"}, token_from=f"/imadd.php?ID={row}")
    listing = sql("SELECT imID, imQTY, imPRICE FROM itemmarket WHERE imADDER = 2")
    check(len(listing) == 1 and listing[0][1:] == ["3", "400"] and inv(2, 2) == 0, "item market: listing three bats takes them out of the seller's inventory", (listing, body[-150:]))
    lid = listing[0][0]
    sql("UPDATE users SET money = 1000 WHERE userid = 3")
    (_, _, body), _ = buyer.form(f"/itemmarket.php?action=buy&ID={lid}", {"QTY": "2"}, token_from=f"/itemmarket.php?action=buy&ID={lid}")
    check(balances(3)["money"] == 200 and balances(2)["money"] == 800 and inv(3, 2) == 2 and sql(f"SELECT imQTY FROM itemmarket WHERE imID = {lid}") == [["1"]],
          "buying two of the three: $800 goes from buyer to seller, two bats arrive, one stays listed", (balances(3), balances(2), inv(3, 2), body[-200:]))
    (_, _, body), _ = buyer.form(f"/itemmarket.php?action=buy&ID={lid}", {"QTY": "1"}, token_from=f"/itemmarket.php?action=buy&ID={lid}")
    check(balances(3)["money"] == 200 and inv(3, 2) == 2 and sql(f"SELECT imQTY FROM itemmarket WHERE imID = {lid}") == [["1"]], "a buyer who cannot afford it gets nothing and the listing stays")
    sql("UPDATE users SET money = 4000 WHERE userid = 3")

    def buy(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get(f"/itemmarket.php?action=buy&ID={lid}")
            return re.search(r"name=['\"]verf['\"] value=['\"]([^'\"]+)", page).group(1)
        _, _, body = sess.get(f"/itemmarket.php?action=buy&ID={lid}", {"QTY": "1", "verf": prepared})
        return "bought" in body.lower() and "error" not in body.lower()
    out = at_once(srv.base, "player2", "playerpass2", 10, buy)
    check(inv(3, 2) == 3 and balances(3)["money"] == 3600 and balances(2)["money"] == 1200 and sql(f"SELECT COUNT(*) FROM itemmarket WHERE imID = {lid}") == [["0"]],
          "ten logins buying the last bat at once: it is bought once, paid for once, and the listing is gone", (sum(out), inv(3, 2), balances(3)["money"], balances(2)["money"]))

    # the crystal market
    sql("UPDATE users SET crystals = 50, money = 0 WHERE userid = 2")
    sql("UPDATE users SET crystals = 0, money = 10000 WHERE userid = 3")
    seller.get("/cmarket.php?action=add", {"amnt": "40", "price": "100"})
    listing = sql("SELECT cmID, cmQTY, cmPRICE FROM crystalmarket WHERE cmADDER = 2")
    check(len(listing) == 1 and listing[0][1:] == ["40", "100"] and balances(2)["crystals"] == 10, "crystal market: listing 40 crystals takes them from the seller", listing)
    seller.get("/cmarket.php?action=add", {"amnt": "11", "price": "100"})
    check(balances(2)["crystals"] == 10 and sql("SELECT SUM(cmQTY) FROM crystalmarket") == [["40"]], "listing more crystals than you hold is refused")
    cid = listing[0][0]
    buyer.get(f"/cmarket.php?action=buy&ID={cid}", {"QTY": "10"})
    check(balances(3) == dict(balances(3), money=9000, crystals=10) and balances(2)["money"] == 1000 and sql(f"SELECT cmQTY FROM crystalmarket WHERE cmID = {cid}") == [["30"]],
          "buying 10 at $100: $1,000 moves to the seller, 10 crystals arrive, 30 stay listed", (balances(3), balances(2)))

    def cbuy(sess, prepare=False, prepared=None):
        if prepare:
            return None
        _, _, body = sess.get(f"/cmarket.php?action=buy&ID={cid}", {"QTY": "30"})
        return "You bought" in body
    out = at_once(srv.base, "player2", "playerpass2", 10, cbuy)
    total_crystals = balances(2)["crystals"] + balances(3)["crystals"] + int(sql("SELECT COALESCE(SUM(cmQTY), 0) FROM crystalmarket")[0][0])
    check(balances(3)["crystals"] == 40 and balances(3)["money"] == 6000 and balances(2)["money"] == 4000 and total_crystals == 50,
          "ten logins buying the last 30 crystals at once: bought once, and no crystals are made or lost", (sum(out), balances(3), balances(2), total_crystals))
    sql("UPDATE users SET crystals = 20 WHERE userid = 2")
    seller.get("/cmarket.php?action=add", {"amnt": "20", "price": "5"})
    cid2 = sql("SELECT cmID FROM crystalmarket WHERE cmADDER = 2")[0][0]

    def cremove(sess, prepare=False, prepared=None):
        if prepare:
            return None
        _, _, body = sess.get(f"/cmarket.php?action=remove&ID={cid2}")
        return "removed from market" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, cremove)
    check(balances(2)["crystals"] == 20 and sql("SELECT COUNT(*) FROM crystalmarket") == [["0"]], "ten logins taking the same listing back at once: the 20 crystals come back once", (sum(out), balances(2)["crystals"]))


def main():
    with Server(8770) as srv:
        shop(srv)
        markets(srv)
        bank(srv)
        cyberbank(srv)
        sending(srv)
    print("\nAll flows passed." if not failed else f"\n{failed} flow check(s) FAILED")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
