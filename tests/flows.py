#!/usr/bin/env python3
"""Flow tests: things a player does, end to end, checked against the database.

    python3 tests/flows.py

Needs a fresh game from tools/dev/setup.php. Each flow sets the player's balances, uses the real pages through a real
login, and checks both what the page said and what the database holds afterwards. The "at once" checks log the same
player in on several devices and send the same request from all of them together: money must never be made or lost.
"""
import os
import re
import sys
import threading
import urllib.error
import urllib.parse
import urllib.request

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


def daily(srv):
    print("Daily reward")
    sql("DELETE FROM v3_daily")
    sql("UPDATE users SET money = 0, crystals = 0, level = 1 WHERE userid = 2")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    _, _, body = s.get("/daily.php")
    check("Daily Reward (claim)" in body and "Today is <b>day 1</b>" in body, "the menu and the page show the reward is waiting")
    s.get("/daily.php", {"verf": "wrong"})
    check(balances(2)["money"] == 0, "a claim without the form's token pays nothing")

    def claim(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get("/daily.php")
            return re.search(r"name='verf' value='([^']+)'", page).group(1)
        _, _, body = sess.get("/daily.php", {"verf": prepared})
        return "Day 1 claimed" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, claim)
    check(sum(out) == 1 and balances(2)["money"] == 500, "ten logins claiming at once: paid once", (sum(out), balances(2)["money"]))
    _, _, body = s.get("/daily.php")
    check("Claimed for today" in body and "Daily Reward (claim)" not in body, "the page and the menu then show it as claimed")
    sql("UPDATE v3_daily SET last_claim = CURDATE() - INTERVAL 1 DAY WHERE userid = 2")
    (_, _, body), _ = s.form("/daily.php", {})
    check("Day 2 claimed" in body and balances(2)["money"] == 1250, "the next day continues the streak", balances(2)["money"])
    sql("DELETE FROM v3_daily")
    sql("UPDATE users SET money = 100, crystals = 10 WHERE userid = 2")


def one(query):
    return sql(query)[0][0]


def token(sess, path):
    _, _, page = sess.get(path)
    return re.search(r"name='verf' value='([^']+)'", page).group(1)


def gym(srv):
    print("Gym")
    sql("UPDATE users SET energy = 10, will = 100, jail = 0, hospital = 0 WHERE userid = 2")
    sql("UPDATE userstats SET strength = 10 WHERE userid = 2")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    s.get("/gym.php", {"stat": "Strength", "amnt": "5", "verf": "wrong"})
    check(one("SELECT energy FROM users WHERE userid = 2") == "10", "training without the form's token uses no energy")
    (_, _, body), _ = s.form("/gym.php", {"stat": "Strength", "amnt": "11"})
    check("do not have enough energy" in body and one("SELECT energy FROM users WHERE userid = 2") == "10", "training more times than you have energy is refused")
    (_, _, body), _ = s.form("/gym.php", {"stat": "Strength", "amnt": "4"})
    check("You have gained" in body and one("SELECT energy FROM users WHERE userid = 2") == "6" and float(one("SELECT strength FROM userstats WHERE userid = 2")) > 10,
          "training spends the energy and raises the stat")
    sql("UPDATE users SET energy = 10, will = 100 WHERE userid = 2")
    sql("UPDATE userstats SET strength = 10 WHERE userid = 2")

    def train(sess, prepare=False, prepared=None):
        if prepare:
            return token(sess, "/gym.php")
        _, _, body = sess.get("/gym.php", {"stat": "Strength", "amnt": "10", "verf": prepared})
        return "You have gained" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, train)
    check(sum(out) == 1 and one("SELECT energy FROM users WHERE userid = 2") == "0", "ten logins spending the same 10 energy at once: one trains, and energy never goes below zero",
          (sum(out), one("SELECT energy FROM users WHERE userid = 2")))
    sql("UPDATE users SET energy = 12, will = 100 WHERE userid = 2")


def crimes(srv):
    print("Crimes")
    sql("UPDATE users SET brave = 5, money = 0, crystals = 0, exp = 0, crimexp = 0, jail = 0, hospital = 0 WHERE userid = 2")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    _, _, body = s.get("/docrime.php?c=1")
    b = balances(2)
    check("You find 80 dollars" in body and b["money"] == 80 and b["crystals"] == 1 and one("SELECT brave FROM users WHERE userid = 2") == "4",
          "a crime that always succeeds pays its money and crystals and costs its brave", (b, body[-300:]))
    _, _, body = s.get("/docrime.php?c=3")
    check("not been set up correctly" in body and "uid=" not in body and one("SELECT brave FROM users WHERE userid = 2") == "4",
          "a crime whose formula is PHP code is not run, and costs nothing")
    _, _, body = s.get("/docrime.php?c=999")
    check("Invalid crime" in body, "a crime that does not exist is refused")
    sql("UPDATE users SET brave = 1, money = 0, crystals = 0, exp = 0, level = 1 WHERE userid = 2")

    def crime(sess, prepare=False, prepared=None):
        if prepare:
            return None
        _, _, body = sess.get("/docrime.php?c=1")
        return "You find 80 dollars" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, crime)
    check(sum(out) == 1 and balances(2)["money"] == 80 and one("SELECT brave FROM users WHERE userid = 2") == "0",
          "ten logins committing a crime on the last point of brave: one crime, one payout", (sum(out), balances(2)["money"]))
    sql("UPDATE users SET brave = 5, money = 100, crystals = 10, exp = 0, crimexp = 0, jail = 0 WHERE userid = 2")


def temple(srv):
    print("Crystal temple")
    price, iq, cash = (int(one(f"SELECT conf_value FROM settings WHERE conf_name = '{n}'")) for n in ("ct_refillprice", "ct_iqpercrys", "ct_moneypercrys"))
    sql(f"UPDATE users SET crystals = {price}, energy = 0, money = 0 WHERE userid = 2")
    sql("UPDATE userstats SET IQ = 10 WHERE userid = 2")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    s.get("/crystaltemple.php?spend=refill2", {"verf": "wrong"})
    check(balances(2)["crystals"] == price, "a refill without the form's token takes nothing")

    def refill(sess, prepare=False, prepared=None):
        if prepare:
            return token(sess, "/crystaltemple.php?spend=refill")
        _, _, body = sess.get("/crystaltemple.php?spend=refill2", {"verf": prepared})
        return "to refill your energy bar" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, refill)
    check(sum(out) == 1 and balances(2)["crystals"] == 0 and one("SELECT energy = maxenergy FROM users WHERE userid = 2") == "1",
          "ten logins buying a refill with crystals for one: one refill, and crystals never go below zero", (sum(out), balances(2)["crystals"]))

    sql("UPDATE users SET crystals = 10, money = 0 WHERE userid = 2")

    def swap_iq(sess, prepare=False, prepared=None):
        if prepare:
            return token(sess, "/crystaltemple.php?spend=IQ")
        _, _, body = sess.get("/crystaltemple.php?spend=IQ2", {"crystals": "10", "verf": prepared})
        return "You traded" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, swap_iq)
    got = float(one("SELECT IQ FROM userstats WHERE userid = 2"))
    check(sum(out) == 1 and balances(2)["crystals"] == 0 and got == 10 + 10 * iq, "ten logins swapping the same 10 crystals for IQ at once: the IQ is given once", (sum(out), got))

    sql("UPDATE users SET crystals = 10, money = 0 WHERE userid = 2")

    def swap_cash(sess, prepare=False, prepared=None):
        if prepare:
            return token(sess, "/crystaltemple.php?spend=money")
        _, _, body = sess.get("/crystaltemple.php?spend=money2", {"crystals": "10", "verf": prepared})
        return "You traded" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, swap_cash)
    b = balances(2)
    check(sum(out) == 1 and b["crystals"] == 0 and b["money"] == 10 * cash, "ten logins swapping the same 10 crystals for money at once: the money is given once", (sum(out), b))
    (_, _, body), _ = s.form("/crystaltemple.php?spend=money2", {"crystals": "1"}, token_from="/crystaltemple.php?spend=money")
    check("do not have enough crystals" in body and balances(2)["money"] == 10 * cash, "swapping crystals you do not have is refused")
    sql("UPDATE users SET crystals = 10, money = 100, energy = 12 WHERE userid = 2")
    sql("UPDATE userstats SET IQ = 10 WHERE userid = 2")


def crons(srv):
    print("Timed updates (crons run by page loads)")
    sql("UPDATE users SET brave = 0, maxbrave = 5, energy = 0, maxenergy = 12, donatordays = 0 WHERE userid = 2")

    def load(sess, prepare=False, prepared=None):
        if prepare:             # everyone is logged in by now: make the update due
            sql("UPDATE cron_times SET last_run = NOW() - INTERVAL 6 MINUTE WHERE name = 'minute-5'")
            sql("UPDATE users SET brave = 0 WHERE userid = 2")
            sql("DELETE FROM logs_cron_runtimes")
            return None
        return sess.get("/explore.php")[0] == 200
    out = at_once(srv.base, "player1", "playerpass1", 10, load)
    brave = one("SELECT brave FROM users WHERE userid = 2")
    runs = one("SELECT COUNT(*) FROM logs_cron_runtimes WHERE cron = 'minute-5'")
    check(all(out) and brave == "1" and runs == "1", "ten page loads arriving when a five-minute update is due: it runs once, so brave regenerates once", (brave, runs))
    sql("UPDATE cron_times SET last_run = NOW() + INTERVAL 1 DAY")
    sql("UPDATE users SET brave = 5, energy = 12 WHERE userid = 2")

    # levelling up: level 1 needs 17 experience
    sql("UPDATE users SET level = 1, exp = 40, brave = 0, maxbrave = 5, energy = 0, maxenergy = 12 WHERE userid = 2")
    out = at_once(srv.base, "player1", "playerpass1", 10, lambda sess, prepare=False, prepared=None: None if prepare else sess.get("/explore.php")[0] == 200)
    lv = sql("SELECT level, exp, brave, maxbrave FROM users WHERE userid = 2")[0]
    check([float(x) for x in lv] == [2, 23, 2, 7], "ten page loads arriving when a level-up is due: one level, one set of bonuses, and the spare experience is kept", lv)
    sql("UPDATE users SET level = 1, exp = 0, brave = 5, maxbrave = 5, energy = 12, maxenergy = 12, hp = 100, maxhp = 100 WHERE userid = 2")


def missions(srv):
    print("Missions and achievements")
    for t in ("v3_progress", "v3_achievements", "v3_missions"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET brave = 50, maxbrave = 50, money = 0, crystals = 0, exp = 0, level = 1, energy = 12 WHERE userid = 2")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    _, _, body = s.get("/missions.php")
    check(body.count("In progress") == 3 and one("SELECT COUNT(*) FROM v3_missions WHERE userid = 2") == "3", "the missions page hands out three missions")
    sql("UPDATE v3_missions SET mission = 'crimes', stat = 'crimes', target = 2, reward = 700 WHERE userid = 2 AND slot = 1")
    sql("UPDATE v3_missions SET mission = 'gym', stat = 'gym', target = 3, reward = 800 WHERE userid = 2 AND slot = 2")
    sql("UPDATE v3_missions SET mission = 'shop', stat = 'shop_buy', target = 1, reward = 900 WHERE userid = 2 AND slot = 3")
    s.get("/docrime.php?c=1")
    s.get("/docrime.php?c=1")
    s.form("/gym.php", {"stat": "Agility", "amnt": "3"})
    money = balances(2)["money"]
    s.form("/itembuy.php?ID=1", {"qty": "1"}, token_from="/shops.php?shop=1")
    prog = sql("SELECT progress FROM v3_missions WHERE userid = 2 ORDER BY slot")
    check(prog == [["2"], ["3"], ["1"]] and one("SELECT amount FROM v3_progress WHERE userid = 2 AND stat = 'crimes'") == "2",
          "committing crimes, training and shopping through the real pages move the matching missions", prog)
    _, _, body = s.get("/missions.php")
    check(body.count("value='Collect'") == 3, "finished missions offer their reward")
    s.get("/missions.php", {"slot": "1", "verf": "wrong"})
    check(one("SELECT claimed FROM v3_missions WHERE userid = 2 AND slot = 1") == "0", "collecting without the form's token pays nothing")
    sql("UPDATE users SET money = 0, crystals = 0 WHERE userid = 2")

    def collect(sess, prepare=False, prepared=None):
        if prepare:
            return token(sess, "/missions.php")
        _, _, body = sess.get("/missions.php", {"slot": "1", "verf": prepared})
        return "Mission complete" in body
    out = at_once(srv.base, "player1", "playerpass1", 10, collect)
    check(sum(out) == 1 and balances(2)["money"] == 700, "ten logins collecting the same mission at once: paid once", (sum(out), balances(2)["money"]))

    # the last two collected at the same moment: both pay, the all-three bonus is paid once
    sessions = []

    def last_two(sess, prepare=False, prepared=None):
        if prepare:
            sessions.append(sess)
            return token(sess, "/missions.php")
        slot = "2" if sessions.index(sess) % 2 == 0 else "3"
        _, _, body = sess.get("/missions.php", {"slot": slot, "verf": prepared})
        return "Mission complete" in body
    out = at_once(srv.base, "player1", "playerpass1", 8, last_two)
    b = balances(2)
    check(sum(out) == 2 and b["money"] == 700 + 800 + 900 and b["crystals"] == 3, "the last two missions collected together: each pays once, and the bonus for all three is paid once", (sum(out), b))
    _, _, body = s.get("/missions.php")
    check("All done for today" in body, "the page then shows the day as done")

    sql("UPDATE users SET brave = 50, money = 0, crystals = 0 WHERE userid = 2")
    sql("UPDATE v3_progress SET amount = 9 WHERE userid = 2 AND stat = 'crimes'")
    out = at_once(srv.base, "player1", "playerpass1", 10, lambda sess, prepare=False, prepared=None: None if prepare else "You find 80" in sess.get("/docrime.php?c=1")[2])
    got = one("SELECT COUNT(*) FROM v3_achievements WHERE userid = 2 AND ach = 'crimes_10'")
    check(sum(out) == 10 and got == "1" and balances(2)["money"] == 10 * 80 + 1000, "ten crimes at once crossing an achievement's target: unlocked and paid once", (sum(out), got, balances(2)["money"]))
    _, _, body = s.get("/achievements.php")
    check("Petty Thief" in body and "Unlocked" in body and "Your Achievements" in body, "the achievements page shows what is unlocked")
    _, _, body = s.get("/achievements.php?u=3")
    check("Achievements of player2" in body, "you can look at another player's achievements")
    for t in ("v3_progress", "v3_achievements", "v3_missions"):
        sql(f"DELETE FROM {t}")
    sql("DELETE FROM inventory WHERE inv_userid = 2")
    sql("UPDATE users SET brave = 5, maxbrave = 5, money = 100, crystals = 10, exp = 0, level = 1, energy = 12, maxenergy = 12, crimexp = 0 WHERE userid = 2")
    sql("UPDATE userstats SET strength = 10, agility = 10 WHERE userid = 2")


def fights(srv):
    print("Fights")
    for t in ("v3_progress", "v3_achievements", "v3_missions", "attacklogs", "events"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET attacking = 0, hospital = 0, jail = 0, gang = 0, hp = 100, maxhp = 100, level = 1, exp = 0")
    sql("UPDATE users SET money = 0, energy = 12, maxenergy = 12, equip_primary = 2, location = 1 WHERE userid = 2")
    sql("UPDATE userstats SET strength = 100000, agility = 100000, guard = 100000 WHERE userid = 2")
    sql("UPDATE users SET money = 10000, location = 1 WHERE userid = 3")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    _, _, body = s.get("/attacklost.php?ID=3")
    check("You have not just lost a fight" in body and one("SELECT COUNT(*) FROM events WHERE evUSER = 3") == "0" and one("SELECT COUNT(*) FROM attacklogs") == "0",
          "the 'you lost' page does nothing unless you have just lost a fight")
    _, _, body = s.get("/attackwon.php?ID=3")
    check("Cheaters" in body and balances(3)["money"] == 10000, "the 'you won' page does nothing unless you have just won that fight")

    def beat(sess):
        for step in range(1, 40):
            _, _, body = sess.get(f"/attack.php?ID=3&wepid=2&nextstep={step * 2 - 1}")
            if "Mug Them" in body:
                return True
        return False

    def mug(sess, prepare=False, prepared=None):
        if prepare:
            return beat(sess)
        _, _, body = sess.get("/attackwon.php?ID=3", {})
        return "snatch" in body
    out = at_once(srv.base, "player1", "playerpass1", 8, mug)
    a, b = balances(2)["money"], balances(3)["money"]
    check(sum(out) == 1 and a + b == 10000 + 500 and 10000 - b >= 20 and one("SELECT hp FROM users WHERE userid = 3") == "1"
          and one("SELECT COUNT(*) FROM attacklogs") == "1" and one("SELECT amount FROM v3_progress WHERE userid = 2 AND stat = 'fights_won'") == "1",
          "eight logins that each won the same fight, all mugging at once: the loser is mugged once and no money appears from nowhere", (sum(out), a, b))
    check(one("SELECT energy FROM users WHERE userid = 2") == "6", "starting the fight cost half the energy bar, once")
    for t in ("v3_progress", "v3_achievements", "v3_missions", "attacklogs", "events"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET attacking = 0, hospital = 0, hp = 100, maxhp = 100, level = 1, exp = 0, money = 100, crystals = 10, energy = 12, equip_primary = 0, new_events = 0")
    sql("UPDATE userstats SET strength = 10, agility = 10, guard = 10")


def bounties(srv):
    print("Bounties")
    for t in ("v3_bounties", "v3_progress", "v3_achievements", "v3_missions", "attacklogs", "events"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET attacking = 0, hospital = 0, jail = 0, gang = 0, hp = 100, maxhp = 100, level = 1, exp = 0, money = 0, location = 1")
    sql("UPDATE users SET money = 11000 WHERE userid = 4")
    placer = Session(srv.base)
    placer.login("player3", "playerpass3")
    placer.get("/bounties.php", {"do": "place", "target": "3", "amount": "5000", "verf": "wrong"})
    check(balances(4)["money"] == 11000, "placing a bounty without the form's token costs nothing")

    def place(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get("/bounties.php")
            return re.search(r"name='verf' value='([^']+)' /><input type='hidden' name='do' value='place'", page).group(1)
        _, _, body = sess.get("/bounties.php", {"do": "place", "target": "3", "amount": "5000", "verf": prepared})
        return "Bounty placed" in body
    out = at_once(srv.base, "player3", "playerpass3", 6, place)
    check(sum(out) == 2 and balances(4)["money"] == 0 and one("SELECT COALESCE(SUM(amount), 0) FROM v3_bounties WHERE status = 'open'") == "10000",
          "six logins each placing a 5,000 bounty with money for two: two are placed, and the pocket never goes below zero", (sum(out), balances(4)["money"]))
    _, _, body = placer.get("/bounties.php")
    check("player2" in body and "$10,000" in body and body.count("value='Withdraw'") == 2, "the hitlist shows the target and the total, and the placer can withdraw")
    bid = one("SELECT MIN(id) FROM v3_bounties")

    def withdraw(sess, prepare=False, prepared=None):
        if prepare:
            _, _, page = sess.get("/bounties.php")
            return re.search(r"name='verf' value='([^']+)' /><input type='hidden' name='do' value='withdraw'", page).group(1)
        _, _, body = sess.get("/bounties.php", {"do": "withdraw", "id": bid, "verf": prepared})
        return "Bounty withdrawn" in body
    out = at_once(srv.base, "player3", "playerpass3", 6, withdraw)
    check(sum(out) == 1 and balances(4)["money"] == 5000, "six logins withdrawing the same bounty at once: refunded once", (sum(out), balances(4)["money"]))

    # the hunter: player1 beats player2 and hospitalises them, from several logins at once
    sql("UPDATE users SET energy = 12, maxenergy = 12, equip_primary = 2 WHERE userid = 2")
    sql("UPDATE userstats SET strength = 100000, agility = 100000, guard = 100000 WHERE userid = 2")

    def hunt(sess, prepare=False, prepared=None):
        if prepare:
            for step in range(1, 40):
                if "Hospitalize Them" in sess.get(f"/attack.php?ID=3&wepid=2&nextstep={step * 2 - 1}")[2]:
                    return True
            return False
        _, _, body = sess.get("/attackbeat.php?ID=3", {})
        return "Bounty collected" in body
    out = at_once(srv.base, "player1", "playerpass1", 6, hunt)
    m = balances(2)["money"]
    check(sum(out) == 1 and m == 5000 + 500 + 1000 and one("SELECT COUNT(*) FROM v3_bounties WHERE status = 'collected' AND collector = 2") == "1",
          "six logins finishing the same fight at once: the 5,000 bounty is paid once (plus the First Blood and Bounty Hunter rewards)", (sum(out), m))
    _, _, body = placer.get("/bounties.php")
    check("Recently collected" in body and "Nobody has a price on their head" in body, "the hitlist is then empty and shows the collection")
    for t in ("v3_bounties", "v3_progress", "v3_achievements", "v3_missions", "attacklogs", "events"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET attacking = 0, hospital = 0, hp = 100, maxhp = 100, level = 1, exp = 0, money = 100, crystals = 10, energy = 12, equip_primary = 0, new_events = 0")
    sql("UPDATE userstats SET strength = 10, agility = 10, guard = 10")


def many(srv, user, password, n, path, data=None, token_path=None, token_re=None):
    """n logins sending the same request at once; returns the page bodies"""
    def go(sess, prepare=False, prepared=None):
        if prepare:
            if token_path is None:
                return None
            page = sess.get(token_path)[2]
            return re.search(token_re or r"name='verf' value='([^']+)'", page).group(1)
        d = None if data is None and prepared is None else dict(data or {}, **({"verf": prepared} if prepared else {}))
        return sess.get(path() if callable(path) else path, d)[2]
    return at_once(srv.base, user, password, n, go)


def town(srv):
    print("Casino, housing, jail, travel")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    # slots and roulette: one stake, many plays at once
    # (a win can pay for another play, so the test is that every play was paid for and the money adds up exactly)
    for page, extra, stake in (("slotsmachine.php", {}, 250), ("roulette.php", {"number": "7"}, 150)):
        sql(f"UPDATE users SET money = {stake}, level = 1 WHERE userid = 2")
        n = iter(range(100, 999))
        out = many(srv, "player1", "playerpass1", 10, lambda: f"/{page}?tresde={next(n)}", dict({"bet": str(stake)}, **extra))
        played = sum("You bet" in b for b in out)
        refused = sum("more than you have" in b for b in out)
        won = sum("lining up 2" in b for b in out) * stake * 3 + sum("lining up 3" in b for b in out) * stake * 26 + sum("matching the number" in b for b in out) * stake * 37
        m = balances(2)["money"]
        check(played >= 1 and played + refused == 10 and m == stake - stake * played + won and (won > 0 or played == 1),
              f"{page}: ten plays at once on one stake: every play is paid for, and with no win there is exactly one play", (played, refused, won, m))
    # lucky boxes
    sql("UPDATE users SET money = 1000, boxes_opened = 0 WHERE userid = 2")
    out = many(srv, "player1", "playerpass1", 10, "/lucky.php?open=1")
    opened = one("SELECT boxes_opened FROM users WHERE userid = 2")
    check(int(opened) >= 1 and balances(2)["money"] >= 0, "lucky boxes: ten opened at once with money for one: the pocket never goes below zero (a box can pay for the next)", (opened, balances(2)["money"]))
    sql("UPDATE users SET money = 100000, boxes_opened = 0 WHERE userid = 2")
    many(srv, "player1", "playerpass1", 10, "/lucky.php?open=1")
    check(one("SELECT boxes_opened FROM users WHERE userid = 2") == "5", "lucky boxes: ten opened at once with plenty of money: the daily limit of five holds")
    # housing
    sql("UPDATE users SET money = 20000, maxwill = 100, will = 100 WHERE userid = 2")
    out = many(srv, "player1", "playerpass1", 10, "/estate.php?property=2")
    b = sql("SELECT money, maxwill FROM users WHERE userid = 2")[0]
    check(sum("Congrats" in x for x in out) == 1 and b == ["0", "150"], "estate agent: ten purchases of the same house at once: bought and paid for once", b)
    out = many(srv, "player1", "playerpass1", 10, "/estate.php?sellhouse")
    b = sql("SELECT money, maxwill FROM users WHERE userid = 2")[0]
    check(sum("You sold your" in x for x in out) == 1 and b == ["20000", "100"], "estate agent: ten sales of the same house at once: sold once (v2 paid ten times)", b)
    # bail: player3 bails player2 out
    sql("UPDATE users SET jail = 30, level = 1 WHERE userid = 3")
    sql("UPDATE users SET money = 5000, jail = 0 WHERE userid = 4")
    out = many(srv, "player3", "playerpass3", 6, "/jailbail.php?ID=3")
    check(sum("You successfully bailed" in x for x in out) == 1 and balances(4)["money"] == 3000 and one("SELECT jail FROM users WHERE userid = 3") == "0",
          "bail: six logins bailing the same prisoner at once: one bail is paid", balances(4)["money"])
    # bust
    sql("UPDATE users SET jail = 30 WHERE userid = 3")
    sql("UPDATE users SET energy = 10, crimexp = 1000000, jail = 0 WHERE userid = 2")
    out = many(srv, "player1", "playerpass1", 6, "/jailbust.php?ID=3")
    e = sql("SELECT energy, crimexp FROM users WHERE userid = 2")[0]
    check(sum("You successfully busted" in x for x in out) <= 1 and e[0] == "0" and int(float(e[1])) <= 1000005,
          "bust: six attempts at once on 10 energy: one attempt, and at most one reward", (e, [x[-120:] for x in out][:2]))
    # travel
    sql("UPDATE users SET money = 1000, location = 1, jail = 0, level = 1 WHERE userid = 2")
    out = many(srv, "player1", "playerpass1", 6, "/monorail.php?to=2")
    b = sql("SELECT money, location FROM users WHERE userid = 2")[0]
    check(sum("Congratulations" in x for x in out) == 1 and b == ["0", "2"], "monorail: six journeys at once on one fare: one ticket", b)
    sql("UPDATE users SET money = 5000 WHERE userid = 2")
    _, _, body = s.get("/monorail.php?to=3")
    check("cannot go there" in body, "monorail: a city above your level is refused")
    sql("UPDATE users SET money = 100, crystals = 10, location = 1, jail = 0, energy = 12, crimexp = 0, maxwill = 100, will = 100, boxes_opened = 0, level = 1, exp = 0")


def items(srv):
    print("Using and sending items")
    sql("DELETE FROM inventory")
    sql("INSERT INTO inventory (inv_itemid, inv_userid, inv_qty) VALUES (1, 2, 1)")
    inv_id = one("SELECT inv_id FROM inventory WHERE inv_userid = 2")
    sql("UPDATE users SET energy = 0, maxenergy = 12 WHERE userid = 2")
    out = many(srv, "player1", "playerpass1", 10, f"/itemuse.php?ID={inv_id}")
    e = one("SELECT energy FROM users WHERE userid = 2")
    check(sum("used successfully" in x for x in out) == 1 and e == "5" and one("SELECT COUNT(*) FROM inventory WHERE inv_userid = 2") == "0",
          "one sandwich eaten from ten logins at once: eaten once, energy rises once (v2: ten times)", (sum("used successfully" in x for x in out), e))
    sql("INSERT INTO inventory (inv_itemid, inv_userid, inv_qty) VALUES (1, 2, 3)")
    inv_id = one("SELECT inv_id FROM inventory WHERE inv_userid = 2")
    sql("UPDATE users SET energy = 10 WHERE userid = 2")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    s.get(f"/itemuse.php?ID={inv_id}")
    check(one("SELECT energy FROM users WHERE userid = 2") == "12", "an item cannot fill a bar past its maximum")
    out = many(srv, "player1", "playerpass1", 10, f"/itemsend.php?ID={inv_id}", {"user": "3", "qty": "2"}, token_path=f"/itemsend.php?ID={inv_id}")
    mine, theirs = inv(2, 1), inv(3, 1)
    check(sum("You sent 2" in x for x in out) == 1 and mine == 0 and theirs == 2, "two sandwiches sent from ten logins at once: they arrive once (v2: duplicated)", (mine, theirs))
    sql("DELETE FROM inventory")
    sql("DELETE FROM itemxferlogs")
    sql("DELETE FROM events")
    sql("UPDATE users SET energy = 12, new_events = 0")


def crafting(srv):
    print("Crafting")
    for t in ("v3_recipes", "v3_recipe_parts", "inventory", "v3_progress", "v3_achievements", "v3_missions", "events"):
        sql(f"DELETE FROM {t}")
    staff = Session(srv.base)
    staff.login("admin", "adminpass1")
    _, _, body = staff.get("/staff_crafting.php")
    check("There are no recipes yet" in body, "staff see the recipe page")
    tok = re.search(r"name='verf' value='([^']+)'", body).group(1)
    form = [("verf", tok), ("do", "save"), ("id", "0"), ("name", "Trophy from scraps"), ("result_qty", "1"), ("result_item", "4"),
            ("part_qty[]", "2"), ("part_item[]", "1"), ("part_qty[]", "1"), ("part_item[]", "2"), ("cost", "100"), ("min_level", "0"), ("enabled", "1")]
    req = urllib.request.Request(srv.base + "/staff_crafting.php", data=urllib.parse.urlencode(form).encode())
    body = staff.open(req, timeout=30).read().decode("latin-1")
    check("Recipe saved" in body and one("SELECT COUNT(*) FROM v3_recipe_parts") == "2", "staff can add a recipe with two ingredients")
    player = Session(srv.base)
    player.login("player1", "playerpass1")
    _, _, body = player.get("/staff_crafting.php")
    check("Trophy from scraps" not in body, "players cannot open the staff recipe page")
    rid = one("SELECT id FROM v3_recipes")
    sql("INSERT INTO inventory (inv_itemid, inv_userid, inv_qty) VALUES (1, 2, 2), (2, 2, 1)")
    sql("UPDATE users SET money = 100 WHERE userid = 2")
    _, _, body = player.get("/craft.php")
    check("Trophy from scraps" in body and "value='Make'" in body, "the workshop lists the recipe and offers to make it")
    out = many(srv, "player1", "playerpass1", 10, "/craft.php", {"recipe": rid, "times": "1"}, token_path="/craft.php")
    got = (inv(2, 4), inv(2, 1), inv(2, 2), balances(2)["money"])
    check(sum("Made!" in x for x in out) == 1 and got == (1, 0, 0, 500), "ten logins crafting at once with ingredients for one: one is made, and nothing is used twice", (sum("Made!" in x for x in out), got))
    _, _, body = player.get("/craft.php")
    check("Missing something" in body, "the workshop then shows what is missing")
    for t in ("v3_recipes", "v3_recipe_parts", "inventory", "v3_progress", "v3_achievements", "v3_missions", "events", "stafflog"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET money = 100, crystals = 10, new_events = 0")


def gangs(srv):
    print("Gangs")
    for t in ("gangs", "gangwars", "gangevents", "events"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET gang = 0, money = 0, crystals = 0")
    sql("UPDATE users SET money = 500000 WHERE userid = 2")
    out = many(srv, "player1", "playerpass1", 6, "/creategang.php", {"submit": "1", "name": "Reds", "desc": "Test gang"}, token_path="/creategang.php")
    b = sql("SELECT money, gang FROM users WHERE userid = 2")[0]
    check(sum("Gang created" in x for x in out) == 1 and one("SELECT COUNT(*) FROM gangs") == "1" and b[0] == "0" and b[1] != "0",
          "six logins founding a gang at once with money for one: one gang, paid for once", (sum("Gang created" in x for x in out), one("SELECT COUNT(*) FROM gangs"), b))
    gid = one("SELECT gangID FROM gangs")
    sql(f"UPDATE users SET gang = {gid} WHERE userid = 3")
    sql("UPDATE users SET money = 1000, crystals = 5 WHERE userid = 2")
    out = many(srv, "player1", "playerpass1", 6, "/yourgang.php?action=donate2", {"money": "1000", "crystals": "5"}, token_path="/yourgang.php?action=donate")
    v = sql("SELECT gangMONEY, gangCRYSTALS FROM gangs")[0]
    check(sum("You donated" in x for x in out) == 1 and v == ["1000", "5"] and balances(2)["money"] == 0 and balances(2)["crystals"] == 0,
          "six logins donating the same 1,000 and 5 crystals at once: the vault receives it once", (sum("You donated" in x for x in out), v))
    out = many(srv, "player1", "playerpass1", 6, "/yourgang.php?action=staff&act2=vault", {"who": "3", "money": "1000", "crystals": "5"}, token_path="/yourgang.php?action=staff&act2=vault")
    v = sql("SELECT gangMONEY, gangCRYSTALS FROM gangs")[0]
    check(sum("was given" in x for x in out) == 1 and v == ["0", "0"] and balances(3)["money"] == 1000 and balances(3)["crystals"] == 5,
          "six logins paying a member the whole vault at once: paid once, and the vault never goes below zero", (sum("was given" in x for x in out), v, balances(3)))
    sql("UPDATE gangs SET gangMONEY = 300")
    sql("UPDATE users SET money = 0")
    out = many(srv, "player1", "playerpass1", 6, "/yourgang.php?action=staff&act2=masspayment", {"amt": "100"}, token_path="/yourgang.php?action=staff&act2=masspayment")
    total = int(one("SELECT SUM(money) FROM users")) + int(one("SELECT gangMONEY FROM gangs"))
    check(total == 300 and int(one("SELECT gangMONEY FROM gangs")) >= 0, "six mass payments at once from a vault of 300: no more than 300 is ever paid out", (total, one("SELECT gangMONEY FROM gangs")))
    sql("UPDATE gangs SET gangMONEY = 100000, gangCAPACITY = 5")
    out = many(srv, "player1", "playerpass1", 6, "/yourgang.php?action=staff&act2=upgrade", {"membs": "1"}, token_path="/yourgang.php?action=staff&act2=upgrade")
    g = sql("SELECT gangMONEY, gangCAPACITY FROM gangs")[0]
    check(g == ["0", "6"], "six capacity upgrades at once with money for one: one slot is added", g)
    for t in ("gangs", "gangwars", "gangevents", "events"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET gang = 0, money = 100, crystals = 10, new_events = 0")


def api(srv):
    print("Notifications bell and the player API")
    import json
    for t in ("events", "mail", "v3_api_keys", "v3_progress", "v3_achievements"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET new_events = 0, new_mail = 0")

    def call(path, key=None, sess=None):
        req = urllib.request.Request(srv.base + path, headers={"X-Api-Key": key} if key else {})
        try:
            r = (sess.open if sess else urllib.request.urlopen)(req, timeout=30)
            return r.status, r.headers, json.loads(r.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            return e.code, e.headers, json.loads(e.read().decode("utf-8"))

    s = Session(srv.base)
    s.login("player1", "playerpass1")
    _, _, body = s.get("/explore.php")
    check("id='mcc-bell'" in body and "Nothing new" in body and "js/notify.js" in body, "every game page carries the bell, empty when nothing is waiting")
    sql("INSERT INTO events (evUSER, evTIME, evREAD, evTEXT) VALUES (2, UNIX_TIMESTAMP(), 0, '<a href=\\'viewuser.php?u=3\\'>player2</a> sent you a test event')")
    sql("UPDATE users SET new_events = 1 WHERE userid = 2")
    _, _, body = s.get("/explore.php")
    check(">1</b>" in body and "player2 sent you a test event" in body, "an unread event shows in the bell with its count")
    code, headers, j = call("/api.php?do=notifications", sess=s)
    check(code == 200 and j["ok"] and j["data"]["total"] == 1 and j["data"]["items"][0]["text"] == "player2 sent you a test event" and "application/json" in headers.get("Content-Type", ""),
          "the page's own script can fetch the same list as JSON", j)
    s.get("/events.php")
    code, _, j = call("/api.php?do=notifications", sess=s)
    check(j["data"]["total"] == 0, "reading the events page clears the bell")
    code, _, j = call("/api.php?do=notifications")
    check(code == 401 and j["ok"] is False, "notifications are refused without a login")
    code, _, j = call("/api.php?do=me")
    check(code == 401, "do=me is refused without a key")
    code, _, j = call("/api.php?do=game")
    check(code == 200 and j["data"]["players"] == 4, "do=game needs no key")
    code, _, j = call("/api.php?do=top&by=level&limit=3")
    check(code == 200 and len(j["data"]["top"]) == 3 and j["data"]["top"][0]["rank"] == 1, "do=top needs no key")
    code, _, j = call("/api.php")
    check(code == 400 and "do=" in j["error"], "a request that asks for nothing is told what it can ask for")
    # make a key through the page
    (_, _, body), _ = s.form("/apikey.php", {"do": "create"})
    key = re.search(r"value='([0-9a-f]{40})'", body)
    check(key is not None and "shown once" in body, "a player can create a key, shown once")
    key = key.group(1)
    _, _, body = s.get("/apikey.php")
    check(key not in body and "Replace with a new key" in body, "the key is not shown again")
    code, _, j = call("/api.php?do=me", key=key)
    check(code == 200 and j["data"]["name"] == "player1" and j["data"]["money"] == balances(2)["money"], "do=me with the key gives the player's own data", j)
    code, _, j = call(f"/api.php?do=me&key={key}")
    check(code == 200 and j["data"]["id"] == 2, "the key also works in the address")
    code, _, j = call("/api.php?do=player&id=3", key=key)
    check(code == 200 and j["data"]["name"] == "player2" and "money" not in j["data"], "do=player gives another player's public details only")
    code, _, j = call("/api.php?do=player&id=99999", key=key)
    check(code == 404, "do=player for nobody is a 404")
    code, _, j = call("/api.php?do=notifications", key=key)
    check(code == 403, "a key cannot read the notifications list")
    code, _, j = call("/api.php?do=me", key="0" * 40)
    check(code == 401, "a wrong key is refused")
    before = balances(2)
    anon = Session(srv.base)
    sent = [anon.get(f"{path}{'&' if '?' in path else '?'}key={key}")[0] for path in ("/sendcash.php?ID=3", "/bank.php", "/daily.php")]
    check(balances(2) == before and sent == [302, 302, 302], "a key does not log you in: game pages send it to the login page", sent)
    codes = [call("/api.php?do=game", key=key)[0] for _ in range(65)]
    check(codes.count(200) <= 60 and 429 in codes, "after 60 requests in a minute a key is told to slow down", (codes.count(200), codes.count(429)))
    (_, _, body), _ = s.form("/apikey.php", {"do": "revoke"})
    sql("UPDATE v3_api_keys SET window_calls = 0")
    code, _, j = call("/api.php?do=me", key=key)
    check("Key revoked" in body and code == 401, "a revoked key stops working")
    _, _, body = s.get("/record.php")
    check("Your Record" in body and "Leaderboards" in body, "the record page shows your counters and the leaderboards")
    _, _, body = s.get("/record.php?u=3&by=crimes")
    check("Record of player2" in body, "you can look at another player's record")
    for t in ("events", "mail", "v3_api_keys", "v3_progress", "v3_achievements"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET new_events = 0, new_mail = 0")


def dashboard(srv):
    print("Staff dashboard")
    sql("DELETE FROM v3_snapshots")
    sql("UPDATE settings SET conf_value = '' WHERE conf_name = 'engine_snapshot_day'")
    out = many(srv, "player1", "playerpass1", 8, "/explore.php")
    check(one("SELECT COUNT(*) FROM v3_snapshots") == "1" and one("SELECT conf_value = CURDATE() FROM settings WHERE conf_name = 'engine_snapshot_day'") == "1",
          "the first page loads of the day, arriving together, record one snapshot of the game")
    staff = Session(srv.base)
    staff.login("admin", "adminpass1")
    _, _, body = staff.get("/staff_dashboard.php")
    check(all(x in body for x in ("Dashboard", "Books balance", "Worth a look", "Timed job", "Richest players")) and "mccodes.com" not in body, "an administrator sees the whole dashboard")
    _, _, body = staff.get("/staff.php")
    check("update_check" not in body and "<iframe" not in body, "the staff index no longer loads a page from another website")
    player = Session(srv.base)
    player.login("player1", "playerpass1")
    _, _, body = player.get("/staff_dashboard.php")
    check("Books balance" not in body and "Richest players" not in body, "a player cannot open the dashboard")
    sql("DELETE FROM logs_cron_runtimes")
    staff.get("/staff.php?action=fire-cron", {"submit": "1", "cron": "minute-5"})
    check(one("SELECT COUNT(*) FROM logs_cron_runtimes") == "0", "firing a cron without the form's token does nothing")
    (_, _, body), _ = staff.form("/staff.php?action=fire-cron", {"submit": "1", "cron": "minute-5"}, token_from="/staff.php?action=fire-cron")
    check("cron fired" in body and one("SELECT COUNT(*) FROM logs_cron_runtimes") == "1", "with the token, an administrator can fire a cron")
    sql("DELETE FROM v3_snapshots")
    sql("DELETE FROM stafflog")
    sql("UPDATE users SET brave = 5, energy = 12, will = 100, hp = 100")


def forums(srv):
    print("Forums")
    for t in ("forum_posts", "forum_topics", "forum_forums"):
        sql(f"DELETE FROM {t}")
    sql("INSERT INTO forum_forums (ff_id, ff_name, ff_desc, ff_lp_poster_name, ff_lp_t_name, ff_auth) VALUES (5, 'General', 'Talk', 'N/A', 'N/A', 'public'), (6, 'Other', 'Elsewhere', 'N/A', 'N/A', 'public')")
    player = Session(srv.base)
    player.login("player1", "playerpass1")
    nasty = "[url=jav&#x61;script:alert(1)]click[/url] <script>alert(2)</script> [style=position:fixed;top:0;left:0;width:100%;height:100%]cover[/style] [b]fine[/b]"
    (_, _, body), _ = player.form("/forums.php?act=newtopic&forum=5", {"ft_name": "Hello", "ft_desc": "A topic", "fp_text": nasty}, token_from="/forums.php?act=newtopicform&forum=5")
    tid = one("SELECT COALESCE(MAX(ft_id), 0) FROM forum_topics")
    check(tid != "0", "a player can start a topic", body[-300:])
    _, _, body = player.get(f"/forums.php?viewtopic={tid}")
    check("<b>fine</b>" in body and "<a>click</a>" in body and "href='jav" not in body and "<script>alert(2)" not in body and "position:fixed" not in body and "<span>cover</span>" in body,
          "a post with a disguised script link, a script tag and a full-page overlay is shown with all three disarmed")
    check("act=deletopic" not in body and "act=lock" not in body, "a player is not offered the moderator links")
    # v2: any player could move a topic (the permission test was written the wrong way round)
    player.get(f"/forums.php?act=move&topic={tid}", {"forum": "6"})
    player.get(f"/forums.php?act=lock&topic={tid}")
    player.get(f"/forums.php?act=deletopic&topic={tid}")
    t = sql(f"SELECT ft_forum_id, ft_locked FROM forum_topics WHERE ft_id = {tid}")
    check(t == [["5", "0"]], "a player cannot move, lock or delete a topic", t)
    mod = Session(srv.base)
    mod.login("admin", "adminpass1")
    mod.get(f"/forums.php?act=lock&topic={tid}")
    mod.get(f"/forums.php?act=deletopic&topic={tid}")
    mod.get(f"/forums.php?act=move&topic={tid}", {"forum": "6"})
    t = sql(f"SELECT ft_forum_id, ft_locked FROM forum_topics WHERE ft_id = {tid}")
    check(t == [["5", "0"]], "a moderator's browser following a bare link (as another site could make it) changes nothing", t)
    _, _, body = mod.get(f"/forums.php?viewtopic={tid}")
    tk = re.search(r"act=lock&amp;topic=\d+&amp;tk=([0-9a-f]{32})", body)
    check(tk is not None, "the moderator's own page carries the links with a token")
    tk = tk.group(1)
    mod.get(f"/forums.php?act=lock&topic={tid}&tk={tk}")
    mod.get(f"/forums.php?act=move&topic={tid}&tk={tk}", {"forum": "6"})
    t = sql(f"SELECT ft_forum_id, ft_locked FROM forum_topics WHERE ft_id = {tid}")
    check(t == [["6", "1"]], "with the token the moderator can lock and move the topic", t)
    mod.get(f"/forums.php?empty=1&code=kill")
    check(one("SELECT COUNT(*) FROM forum_topics") == "1", "the old address that erased every forum does nothing")
    mod.get(f"/forums.php?act=deletopic&topic={tid}&tk={tk}")
    check(one("SELECT COUNT(*) FROM forum_topics") == "0", "with the token the moderator can delete the topic")
    for t in ("forum_posts", "forum_topics", "forum_forums", "stafflog"):
        sql(f"DELETE FROM {t}")


def equipping(srv):
    print("Equipping")
    sql("DELETE FROM inventory")
    sql("UPDATE users SET equip_primary = 2, equip_secondary = 0, equip_armor = 0 WHERE userid = 2")
    out = many(srv, "player1", "playerpass1", 10, "/unequip.php?type=equip_primary")
    check(sum("successfully unequipped" in x for x in out) == 1 and inv(2, 2) == 1 and one("SELECT equip_primary FROM users WHERE userid = 2") == "0",
          "ten logins unequipping one weapon at once: one weapon comes back (v2: ten)", (sum("successfully unequipped" in x for x in out), inv(2, 2)))
    sql("UPDATE users SET equip_armor = 3 WHERE userid = 2")
    sql("INSERT INTO inventory (inv_itemid, inv_userid, inv_qty) VALUES (3, 2, 1)")
    # (item 3 is armour; equip a second jacket over the first: the old one must come back exactly once)
    sql("UPDATE items SET armor = 10 WHERE itmid = 4")
    sql("INSERT INTO inventory (inv_itemid, inv_userid, inv_qty) VALUES (4, 2, 1)")
    inv_id = one("SELECT inv_id FROM inventory WHERE inv_userid = 2 AND inv_itemid = 4")
    out = many(srv, "player1", "playerpass1", 10, f"/equip_armor.php?ID={inv_id}", {"type": "equip_armor"})
    got = (one("SELECT equip_armor FROM users WHERE userid = 2"), inv(2, 3), inv(2, 4))
    check(sum("equipped successfully" in x for x in out) == 1 and got == ("4", 2, 0), "ten logins swapping armour at once: the old piece comes back once and the new one is used once", (sum("equipped successfully" in x for x in out), got))
    inv_id = one("SELECT inv_id FROM inventory WHERE inv_userid = 2 AND inv_itemid = 2")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    _, _, body = s.get(f"/equip_weapon.php?ID={inv_id}", {"type": "equip_secondary"})
    check("equipped successfully" in body and one("SELECT equip_secondary FROM users WHERE userid = 2") == "2" and inv(2, 2) == 0, "a weapon can be equipped to the second slot")
    _, _, body = s.get(f"/equip_weapon.php?ID={inv_id}", {"type": "userpass"})
    check("equipped successfully" not in body, "a slot that is not a slot is refused")
    sql("DELETE FROM inventory")
    sql("UPDATE items SET armor = 0 WHERE itmid = 4")
    sql("UPDATE users SET equip_primary = 0, equip_secondary = 0, equip_armor = 0")


def season(srv):
    print("Season pass")
    for t in ("v3_season", "v3_season_claims"):
        sql(f"DELETE FROM {t}")
    sql("INSERT INTO v3_season (userid, season, xp, premium) VALUES (2, DATE_FORMAT(NOW(), '%Y-%m'), 1200, 0)")
    sql("UPDATE users SET money = 0, crystals = 150 WHERE userid = 2")
    s = Session(srv.base)
    s.login("player1", "playerpass1")
    _, _, body = s.get("/season.php")
    check("Season Pass (3)" in body and "Collect 3 rewards" in body and "Unlock premium for 150 crystals" in body, "the page and the menu show three rewards waiting and the premium offer")

    def grab(sess, prepare=False, prepared=None, do="claim"):
        if prepare:
            page = sess.get("/season.php")[2]
            return re.search(r"name='verf' value='([^']+)' /><input type='hidden' name='do' value='" + do + "'", page).group(1)
        return sess.get("/season.php", {"do": do, "verf": prepared})[2]
    out = at_once(srv.base, "player1", "playerpass1", 8, grab)
    check(balances(2)["money"] == 2500 + 5000 + 7500 and one("SELECT COUNT(*) FROM v3_season_claims") == "3", "eight logins collecting at once: each reward is paid once", balances(2)["money"])
    out = at_once(srv.base, "player1", "playerpass1", 8, lambda sess, prepare=False, prepared=None: grab(sess, prepare, prepared, "buy"))
    check(sum("Premium unlocked" in x for x in out) == 1 and balances(2)["crystals"] == 0, "eight logins buying the pass at once: bought and paid for once", (sum("Premium unlocked" in x for x in out), balances(2)["crystals"]))
    s.get("/season.php", {"do": "claim", "verf": token(s, "/season.php")})
    check(balances(2)["crystals"] == 9 and balances(2)["money"] == 15000 + 30000, "the premium rewards for tiers already reached can then be collected", balances(2))
    for t in ("v3_season", "v3_season_claims"):
        sql(f"DELETE FROM {t}")
    sql("UPDATE users SET money = 100, crystals = 10")


def main():
    with Server(8770) as srv:
        sql("UPDATE users SET level = 1, exp = 0, maxbrave = 5, brave = 5, maxenergy = 12, energy = 12, will = 100, maxwill = 100, jail = 0, hospital = 0, hp = 100, maxhp = 100, attacking = 0")
        sql("UPDATE cron_times SET last_run = NOW() + INTERVAL 1 DAY")      # no timed update lands in the middle of a flow
        crons(srv)
        shop(srv)
        markets(srv)
        bank(srv)
        cyberbank(srv)
        sending(srv)
        daily(srv)
        gym(srv)
        crimes(srv)
        temple(srv)
        missions(srv)
        fights(srv)
        bounties(srv)
        town(srv)
        items(srv)
        crafting(srv)
        gangs(srv)
        api(srv)
        dashboard(srv)
        forums(srv)
        equipping(srv)
        season(srv)
    sql("UPDATE cron_times SET last_run = NOW()")
    print("\nAll flows passed." if not failed else f"\n{failed} flow check(s) FAILED")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
