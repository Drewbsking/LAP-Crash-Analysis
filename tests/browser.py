"""Disposable end-to-end checks; requires Python Playwright and Chromium.

Never uses the preview or production database. Screenshots and logs remain in
ignored test-results/. Set PLAYWRIGHT_CHROMIUM to an existing browser executable.
"""
from pathlib import Path
from datetime import datetime, timedelta, timezone
import json
import os
import re
import socket
import sqlite3
import subprocess
import time
import urllib.request
from urllib.parse import parse_qs, urlparse
import uuid
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "test-results"
OUT.mkdir(exist_ok=True)
DB = OUT / f"browser-{uuid.uuid4().hex[:12]}.sqlite"
with socket.socket() as probe:
    probe.bind(("127.0.0.1", 0))
    PORT = probe.getsockname()[1]
BASE = f"http://127.0.0.1:{PORT}"
ENV = dict(os.environ, APP_ENV="test", APP_DSN=f"sqlite:{DB.as_posix()}", APP_URL=BASE, MAIL_TRANSPORT="log")
FLAGS = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
subprocess.run(["php", "bin/console.php", "init", "--demo"], cwd=ROOT, env=ENV, check=True, capture_output=True, creationflags=FLAGS)
server_log = (OUT / "browser-server.log").open("w", encoding="utf-8")
server = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", "-t", "public"], cwd=ROOT, env=ENV, stdout=server_log, stderr=server_log, creationflags=FLAGS)
checks = []
errors = []

def check(condition, description):
    if not condition:
        raise AssertionError(description)
    checks.append(description)
    print("PASS", description, flush=True)

def login(browser, identity, password="Demo-Only-2026!", **options):
    context = browser.new_context(**options)
    page = context.new_page()
    page.on("pageerror", lambda err: errors.append(str(err)))
    page.goto(BASE + "/login.php")
    page.get_by_label("Email address", exact=True).fill(identity)
    page.get_by_label("Password", exact=True).fill(password)
    page.get_by_role("button", name="Sign in", exact=False).click()
    page.wait_for_url("**/dashboard.php")
    return context, page

def csrf(page):
    return page.locator('input[name="csrf"]').first.input_value()

def no_overflow(page, label):
    check(page.evaluate("document.documentElement.scrollWidth <= innerWidth + 1"), label + " fits viewport")

try:
    for _ in range(80):
        try:
            urllib.request.urlopen(BASE + "/login.php", timeout=1)
            break
        except Exception:
            time.sleep(.15)
    else:
        raise RuntimeError("PHP test server did not start")
    with sync_playwright() as p:
        args = {"headless": True}
        if os.environ.get("PLAYWRIGHT_CHROMIUM"):
            args["executable_path"] = os.environ["PLAYWRIGHT_CHROMIUM"]
        browser = p.chromium.launch(**args)
        anon = browser.new_page(viewport={"width": 1440, "height": 1000})
        anon.goto(BASE + "/project.php?id=1")
        check(anon.url.endswith("/login.php"), "Anonymous project access redirects to sign-in")
        anon.screenshot(path=str(OUT / "login-desktop.png"), full_page=True)
        anon.keyboard.press("Tab")
        check(anon.evaluate("document.activeElement.textContent.trim()") == "Skip to content", "Keyboard users get a skip link first")
        anon.close()

        design_context, design = login(browser, "peters@example.test", viewport={"width":1440,"height":1000})
        design.screenshot(path=str(OUT / "dashboard-desktop.png"), full_page=True)
        no_overflow(design, "Desktop dashboard")
        check(design.get_by_text("Overdue", exact=True).count() > 0, "Overdue review is visible on dashboard")
        check(design.get_by_role("link", name="Orion Road shoulder improvements", exact=True).count() == 1, "Designer sees another requester's project")
        requester_filter=design.get_by_label("REQUESTER",exact=True)
        requester_filter.focus()
        check(design.evaluate("document.activeElement.id")=="requester","Requester filter is keyboard focusable")
        requester_filter.select_option(label="Jacob Mayich")
        design.get_by_role("button",name="Filter",exact=True).click()
        check(all(name=="Jacob Mayich" for name in design.locator("tbody .requester-col").all_text_contents()),"Requester dropdown filters the project table")
        design.get_by_label("FIND A PROJECT",exact=True).fill("Orion")
        design.get_by_label("STATUS",exact=True).select_option("started")
        with sqlite3.connect(DB) as connection:
            construction_year=connection.execute("SELECT construction_year FROM projects WHERE name='Orion Road shoulder improvements'").fetchone()[0]
        design.get_by_label("CONSTRUCTION YEAR",exact=True).select_option(str(construction_year))
        design.get_by_role("button",name="Filter",exact=True).click()
        check(design.locator("tbody tr").count()==1 and design.get_by_role("link",name="Orion Road shoulder improvements",exact=True).count()==1,"Requester filter combines with search, status, and construction year")
        design.get_by_role("link",name="Reset",exact=True).click()
        check(design.get_by_label("REQUESTER",exact=True).input_value()=="" and design.locator("tbody tr").count()==5,"Reset restores all requesters and active projects")
        response=design.goto(BASE + "/admin/users.php")
        check(response.status == 403, "Designer cannot access account administration")
        design.goto(BASE + "/request.php")
        design.get_by_label("Project name", exact=True).fill("Browser test <script>alert(1)</script>")
        design.get_by_label("RCOC project number", exact=True).fill("BROWSER-001")
        design.get_by_label("MDOT job number", exact=True).fill("0000789")
        design.get_by_label("Analysis needed by", exact=True).fill("2027-02-01")
        design.get_by_label("Current bid letting date", exact=True).fill("2027-04-01")
        design.get_by_label("Construction year", exact=True).fill("2027")
        design.get_by_label("Project type", exact=True).select_option("Other")
        expect(design.get_by_label("Describe the project type", exact=True)).to_be_visible()
        design.get_by_label("Describe the project type", exact=True).fill("Bridge approach safety")
        design.get_by_label("Brief project scope", exact=True).fill("Review approach geometry and crash patterns.")
        design.get_by_label("Project limits", exact=True).fill("A Road to B Road")
        design.get_by_role("radio",name="Yes",exact=True).check()
        design.get_by_label("Describe the design exception", exact=True).fill("Existing shoulder width")
        design.get_by_role("checkbox",name="Other",exact=True).check()
        design.get_by_label("Describe the other safety improvements", exact=True).fill("Bridge approach delineation")
        design.screenshot(path=str(OUT / "request-desktop.png"), full_page=True)
        design.get_by_role("button",name="Submit request",exact=False).click()
        design.wait_for_url("**/project.php?id=*")
        project_url=design.url
        project_id=int(re.search(r"id=(\d+)",project_url).group(1))
        expect(design.get_by_role("heading",name="Browser test <script>alert(1)</script>",exact=True)).to_be_visible()
        check(True,"Request form submits conditional fields and escapes project markup")
        check(design.get_by_role("button",name="Save folder & start",exact=True).count()==0,"Design UI does not expose reviewer actions")
        bad=design.request.post(project_url,form={"action":"folder","folder_path":r"G:\A TOC","version":"1","csrf":"bad"})
        check(bad.status==403,"Invalid CSRF token rejected")
        denied=design.request.post(project_url,form={"action":"folder","folder_path":r"G:\A TOC","version":"1","csrf":csrf(design)})
        check("permission" in denied.text(),"Forged reviewer action rejected server-side")

        analyst_context,analyst=login(browser,"jeremy@example.test",viewport={"width":1440,"height":1000},permissions=["clipboard-read","clipboard-write"])
        analyst.goto(project_url)
        analyst.get_by_label("Folder location",exact=True).fill(r"G:\A TOC\Browser review")
        analyst.get_by_role("button",name="Save folder & start",exact=True).click()
        expect(analyst.locator('.timeline [aria-current="step"]')).to_contain_text("Started")
        analyst.get_by_role("button",name="Copy path",exact=True).click()
        expect(analyst.locator("#copy-status")).to_have_text("Folder path copied.")
        check(analyst.evaluate("navigator.clipboard.readText()") == r"G:\A TOC\Browser review", "Copy path preserves spaces and backslashes")
        analyst.get_by_role("checkbox",name="The analysis is ready for review.",exact=True).check()
        analyst.get_by_role("button",name="Mark ready for review",exact=True).click()
        expect(analyst.locator('.timeline [aria-current="step"]')).to_contain_text("Ready for Review")
        analyst.screenshot(path=str(OUT / "project-desktop.png"), full_page=True)
        check(True,"Jeremy starts analysis and marks it Ready for Review")

        # The existing cron command discovers and sends a due reminder.
        with sqlite3.connect(DB) as connection:
            submitted=(datetime.now(timezone.utc)-timedelta(days=15)).strftime("%Y-%m-%d %H:%M:%S")
            connection.execute("UPDATE projects SET created_at=? WHERE id=?",(submitted,project_id))
        cron=subprocess.run(["php","bin/console.php","mail:work"],cwd=ROOT,env=ENV,check=True,capture_output=True,text=True,creationflags=FLAGS)
        with sqlite3.connect(DB) as connection:
            reminder_mail=connection.execute("SELECT m.id,m.recipient,m.state,m.text_body FROM mail_outbox m JOIN reminder_deliveries d ON d.mail_id=m.id JOIN project_reminders r ON r.id=d.reminder_id WHERE r.project_id=?",(project_id,)).fetchall()
            check(len(reminder_mail)==3 and all(m[2]=="sent" and "Status: Ready for Review" in m[3] for m in reminder_mail) and {m[1] for m in reminder_mail}=={"bates@example.test","jeremy@example.test","peters@example.test"},"Existing cron command sends the due status reminder to deduplicated recipients")
            connection.execute("UPDATE mail_outbox SET state='pending' WHERE id=?",(reminder_mail[0][0],))

        admin_context,admin=login(browser,"bates",viewport={"width":1440,"height":1000})
        admin.goto(project_url)
        admin.get_by_role("checkbox",name="Review and external signing are complete.",exact=True).check()
        admin.once("dialog",lambda dialog: dialog.accept())
        admin.get_by_role("button",name="Mark complete",exact=True).click()
        expect(admin.get_by_role("heading",name="Review complete",exact=True)).to_be_visible()
        check(admin.get_by_role("link",name="Edit details",exact=True).count()==0,"Completed projects expose no edit action")
        response=admin.goto(BASE + f"/request.php?id={project_id}")
        check(response.status==403,"Completed project edit URL is forbidden")
        admin.goto(BASE+"/admin/settings.php")
        reminder_row=admin.locator("tr").filter(has_text="Crash analysis reminder").filter(has_text="BROWSER-001").filter(has_text=reminder_mail[0][1])
        check(reminder_row.count()==1 and reminder_row.get_by_text("Cancelled",exact=True).count()==1 and reminder_row.get_by_role("button",name="Retry",exact=True).count()==0,"Completion cancels queued reminder and Notifications does not offer retry")
        check(reminder_row.get_by_role("link",name="View project",exact=True).get_attribute("href").endswith("id="+str(project_id)),"Reminder delivery history links to its project")
        check(admin.locator('input[type="email"]').count()==0,"Notifications no longer has a separate stakeholder email form")
        admin.goto(BASE+"/admin/users.php")
        contacts=admin.locator("#project-contacts")
        expect(contacts.get_by_label("Traffic Safety contact",exact=True).locator("option:checked")).to_have_text("Jeremy Miller")
        expect(contacts.get_by_label("Review contact",exact=True).locator("option:checked")).to_have_text("Andrew Bates")
        bad_contacts=admin.request.post(BASE+"/admin/users.php",form={"action":"contacts","csrf":"invalid","contact_analyst":"2","contact_admin":"1"})
        check(bad_contacts.status==403,"Project contact changes require CSRF protection")
        contacts.get_by_role("button",name="Save project contacts",exact=True).click()
        expect(admin.get_by_text("Project contacts saved. Project emails go to these two accounts and the requester.",exact=True)).to_be_visible()
        check(True,"Administrator manages two account contacts without re-entering email addresses")
        admin.get_by_label("Full name",exact=True).last.fill("Browser Test Designer")
        check(admin.get_by_label("Username",exact=True).count()==0,"Account creation and editing need no separate username")
        admin.get_by_label("Email address",exact=True).fill("browseruser@example.test")
        admin.get_by_role("button",name="Create account",exact=True).click()
        expect(admin.get_by_text("Account created. A welcome email with a password setup link has been sent.",exact=True)).to_be_visible()
        check(admin.locator(".secret-display").count()==0,"Creating staff sends a welcome email without displaying a password")
        def welcome_message():
            with sqlite3.connect(DB) as connection:
                return connection.execute("SELECT text_body, html_body, state FROM mail_outbox WHERE recipient=? ORDER BY id DESC LIMIT 1",("browseruser@example.test",)).fetchone()
        first_welcome=welcome_message()
        check(first_welcome[2]=="sent" and "required project information up front" in first_welcome[0] and "Sign-in email: browseruser@example.test" in first_welcome[0],"Welcome email is delivered through the local transport with sign-in email and portal purpose")
        first_link=re.search(r"Set your password: (\S+)",first_welcome[0]).group(1)
        person=admin.locator(".admin-person").filter(has_text="Browser Test Designer")
        person.get_by_text("Edit account",exact=True).click()
        admin.once("dialog",lambda dialog: dialog.accept())
        person.get_by_role("button",name="Send welcome email",exact=True).click()
        expect(admin.get_by_text("A new welcome email has been sent. Previous password setup links are no longer valid.",exact=True)).to_be_visible()
        latest_welcome=welcome_message()
        setup_link=re.search(r"Set your password: (\S+)",latest_welcome[0]).group(1)
        check(setup_link!=first_link,"Administrator can resend a fresh welcome link from People")
        new_context=browser.new_context()
        newpage=new_context.new_page()
        newpage.on("pageerror",lambda err: errors.append(str(err)))
        newpage.goto(first_link)
        expect(newpage.get_by_text("This link is invalid or has expired.",exact=True)).to_be_visible()
        response=newpage.goto(setup_link)
        check(response.headers.get("referrer-policy")=="no-referrer","Password setup page prevents referrer leakage")
        expect(newpage.get_by_role("heading",name="Set your password",exact=True)).to_be_visible()
        bad_setup=newpage.request.post(BASE+"/reset-password.php",form={"token":setup_link.split("token=")[1],"password":"Browser-Private-Password!","confirm":"Browser-Private-Password!","csrf":"invalid"})
        check(bad_setup.status==403,"Password setup rejects an invalid CSRF token")
        newpage.set_viewport_size({"width":390,"height":900})
        no_overflow(newpage,"Mobile password setup")
        newpage.screenshot(path=str(OUT/"password-setup-mobile.png"),full_page=True)
        newpage.get_by_label("New password",exact=True).fill("Browser-Private-Password!")
        newpage.get_by_label("Confirm new password",exact=True).fill("Different-Private-Password!")
        newpage.get_by_role("button",name="Save password",exact=True).click()
        expect(newpage.get_by_text("The passwords do not match.",exact=True)).to_be_visible()
        newpage.get_by_label("New password",exact=True).fill("Browser-Private-Password!")
        newpage.get_by_label("Confirm new password",exact=True).fill("Browser-Private-Password!")
        newpage.get_by_role("button",name="Save password",exact=True).click()
        newpage.wait_for_url("**/login.php")
        check(True,"Welcome link lets the staff member choose a password without knowing a temporary password")
        for width in [320,390,1440]:
            newpage.set_viewport_size({"width":width,"height":900})
            no_overflow(newpage,f"{width}px sign-in purpose text")
            if width in [390,1440]:
                newpage.screenshot(path=str(OUT/f"login-{width}.png"),full_page=True)
        invited_context,invited=login(browser,"BROWSERUSER@example.test",password="Browser-Private-Password!")
        check(invited.url.endswith("/dashboard.php"),"Invited user signs in normally after password setup")
        invited_context.close()
        newpage.goto(setup_link)
        expect(newpage.get_by_text("This link is invalid or has expired.",exact=True)).to_be_visible()
        check(True,"A completed password setup link cannot be reused")
        admin.reload()
        person=admin.locator(".admin-person").filter(has_text="Browser Test Designer")
        person.get_by_text("Edit account",exact=True).click()
        check(person.get_by_role("button",name="Send welcome email",exact=True).count()==0,"Established accounts no longer show the welcome action")
        # Administrator-generated temporary passwords remain available for recovery.
        admin.once("dialog",lambda dialog: dialog.accept())
        person.get_by_role("button",name="Generate temporary password",exact=True).click()
        temporary=admin.locator(".secret-display").inner_text()
        newpage.goto(BASE+"/login.php")
        newpage.get_by_label("Email address",exact=True).fill("browseruser@example.test")
        newpage.get_by_label("Password",exact=True).fill(temporary)
        newpage.get_by_role("button",name="Sign in",exact=False).click()
        newpage.wait_for_url("**/change-password.php")
        newpage.goto(BASE+"/dashboard.php")
        check(newpage.url.endswith("/change-password.php"),"First-login change cannot be bypassed by a direct URL")
        newpage.get_by_label("Current password",exact=True).fill(temporary)
        newpage.get_by_label("New password",exact=True).fill("Browser-Private-Password!")
        newpage.get_by_label("Confirm new password",exact=True).fill("Browser-Private-Password!")
        newpage.get_by_role("button",name="Save new password",exact=True).click()
        newpage.wait_for_url("**/login.php")
        check(True,"Temporary-password change works end-to-end")
        new_context.close()
        admin.reload()
        check(admin.locator(".secret-display").count()==0,"Temporary credential is shown only once")

        for width in [320,390,768,1024]:
            design.set_viewport_size({"width":width,"height":900})
            for path,label in [("/dashboard.php","dashboard"),("/request.php","request"),("/project.php?id=3","project")]:
                design.goto(BASE+path)
                no_overflow(design,f"{width}px {label}")
                if width==390:
                    design.screenshot(path=str(OUT / f"{label}-mobile.png"),full_page=True)
        admin.set_viewport_size({"width":390,"height":900})
        for path in ["/admin/users.php","/admin/settings.php"]:
            admin.goto(BASE+path)
            no_overflow(admin,"Mobile " + path)
            admin.screenshot(path=str(OUT/("people-mobile.png" if path.endswith("users.php") else "notifications-mobile.png")),full_page=True)

        admin.goto(BASE+"/admin/users.php")
        person=admin.locator(".admin-person").filter(has_text="Browser Test Designer")
        person.get_by_text("Edit account",exact=True).click()
        person.get_by_label("Email",exact=True).fill("updated-browser@example.test")
        person.get_by_role("button",name="Save account",exact=True).click()
        expect(admin.get_by_text("Account updated.",exact=True)).to_be_visible()
        changed_context,changed=login(browser,"updated-browser@example.test",password="Browser-Private-Password!")
        check(changed.url.endswith("/dashboard.php"),"Editing an account email changes its sign-in address while preserving its password")
        changed_context.close()

        # New browser session verifies data was persisted, not held in client state.
        persisted_context,persisted=login(browser,"peters")
        persisted.goto(project_url)
        expect(persisted.get_by_text("Bridge approach safety",exact=True)).to_be_visible()
        check(True,"Project details persist across browser sessions")
        # Disposable pagination fixtures verify every filter survives Next/Previous.
        with sqlite3.connect(DB) as connection:
            connection.row_factory=sqlite3.Row
            fixture=dict(connection.execute("SELECT * FROM projects WHERE id=?",(project_id,)).fetchone())
            fixture.pop("id")
            owner=fixture["requester_id"]
            fixture.update(status="entered",version=1,folder_path="")
            for index in range(27):
                fixture.update(rcoc_number=f"PAGINATION-{index}",name=f"Pagination fixture {index}",submission_token=uuid.uuid4().hex*2)
                connection.execute("INSERT INTO projects("+",".join(fixture)+") VALUES ("+",".join("?" for _ in fixture)+")",tuple(fixture.values()))
        persisted.goto(BASE+f"/dashboard.php?status=entered&year=2027&requester={owner}&q=Pagination+fixture")
        next_link=persisted.get_by_role("link",name="Next",exact=True)
        next_filters=parse_qs(urlparse(next_link.get_attribute("href")).query)
        check(next_filters.get("requester")==[str(owner)] and next_filters.get("status")==["entered"] and next_filters.get("year")==["2027"] and next_filters.get("q")==["Pagination fixture"],"Pagination links preserve requester and all other filters")
        next_link.click()
        check(persisted.locator("tbody tr").count()==2 and persisted.get_by_label("REQUESTER",exact=True).input_value()==str(owner),"Requester filter remains selected on the second page")
        check(not errors,"No JavaScript page errors")
        browser.close()
    server.terminate()
    server.wait(timeout=10)
    server_log.close()
    text=(OUT / "browser-server.log").read_text(encoding="utf-8")
    check(not re.search(r"Fatal error|Warning:|Uncaught|\[500\]",text),"No PHP runtime errors in browser workflow")
    (OUT / "browser-results.json").write_text(json.dumps({"checks":checks,"database":str(DB),"errors":errors},indent=2),encoding="utf-8")
    print(f"\n{len(checks)} browser checks passed.")
finally:
    if server.poll() is None:
        server.terminate()
        server.wait(timeout=10)
    if not server_log.closed:
        server_log.close()
