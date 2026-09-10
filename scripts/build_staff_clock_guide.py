"""Build the DoughBoss staff clock setup and printer guide as a branded PDF."""

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    KeepTogether,
    PageBreak,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "output" / "pdf" / "DoughBoss-Staff-Clock-Setup-Printer-and-Daily-Use-Guide.pdf"

INK = colors.HexColor("#121212")
PAPER = colors.HexColor("#F6F1E8")
CARD = colors.white
RED = colors.HexColor("#DC392D")
GREEN = colors.HexColor("#167A50")
AMBER = colors.HexColor("#A76508")
MUTED = colors.HexColor("#625D55")
LINE = colors.HexColor("#D8D0C3")
PALE_GREEN = colors.HexColor("#E6F5EC")
PALE_RED = colors.HexColor("#FBE9E6")
PALE_AMBER = colors.HexColor("#FFF2D7")


def register_fonts():
    heading_path = ROOT / "docs" / "brand" / "BebasNeue.ttf"
    if heading_path.exists():
        pdfmetrics.registerFont(TTFont("DoughHeading", str(heading_path)))
        return "DoughHeading"
    return "Helvetica-Bold"


HEADING = register_fonts()


class DoughBossGuide(BaseDocTemplate):
    def __init__(self, filename):
        super().__init__(
            filename,
            pagesize=A4,
            leftMargin=18 * mm,
            rightMargin=18 * mm,
            topMargin=20 * mm,
            bottomMargin=18 * mm,
            title="DoughBoss Staff Clock Setup, Printer and Daily Use Guide",
            author="DoughBoss",
            subject="Secure QR and PIN staff attendance setup and operating guide",
        )
        frame = Frame(
            self.leftMargin,
            self.bottomMargin,
            self.width,
            self.height,
            id="body",
            leftPadding=0,
            rightPadding=0,
            topPadding=0,
            bottomPadding=0,
        )
        self.addPageTemplates(PageTemplate(id="main", frames=frame, onPage=self._draw_page))

    def _draw_page(self, canvas, doc):
        canvas.saveState()
        page_width, page_height = A4
        if doc.page == 1:
            canvas.setFillColor(INK)
            canvas.rect(0, 0, page_width, page_height, fill=1, stroke=0)
            canvas.setFillColor(RED)
            canvas.rect(0, 0, 8 * mm, page_height, fill=1, stroke=0)
        else:
            canvas.setFillColor(PAPER)
            canvas.rect(0, 0, page_width, page_height, fill=1, stroke=0)
            canvas.setFillColor(INK)
            canvas.setFont(HEADING, 14)
            canvas.drawString(18 * mm, page_height - 11 * mm, "DOUGH BOSS.")
            canvas.setFillColor(RED)
            canvas.rect(18 * mm, page_height - 13.5 * mm, 22 * mm, 1.4 * mm, fill=1, stroke=0)
            canvas.setFillColor(MUTED)
            canvas.setFont("Helvetica", 8)
            canvas.drawRightString(page_width - 18 * mm, page_height - 11 * mm, "STAFF CLOCK - MANAGER AND STAFF GUIDE")
            canvas.setStrokeColor(LINE)
            canvas.line(18 * mm, 13 * mm, page_width - 18 * mm, 13 * mm)
            canvas.setFillColor(MUTED)
            canvas.drawString(18 * mm, 8.5 * mm, "Version 1.0 - 10 September 2026")
            canvas.drawRightString(page_width - 18 * mm, 8.5 * mm, f"Page {doc.page}")
        canvas.restoreState()


styles = getSampleStyleSheet()
styles.add(ParagraphStyle(
    name="CoverBrand", fontName=HEADING, fontSize=28, leading=30, textColor=colors.white,
    spaceAfter=35 * mm, tracking=2,
))
styles.add(ParagraphStyle(
    name="CoverTitle", fontName=HEADING, fontSize=44, leading=42, textColor=colors.white,
    spaceAfter=8 * mm,
))
styles.add(ParagraphStyle(
    name="CoverSubtitle", fontName="Helvetica", fontSize=14, leading=20, textColor=colors.HexColor("#E2DED7"),
    spaceAfter=10 * mm,
))
styles.add(ParagraphStyle(
    name="H1x", fontName=HEADING, fontSize=28, leading=30, textColor=INK,
    spaceBefore=0, spaceAfter=5 * mm,
))
styles.add(ParagraphStyle(
    name="H2x", fontName=HEADING, fontSize=19, leading=21, textColor=INK,
    spaceBefore=4 * mm, spaceAfter=2.5 * mm,
))
styles.add(ParagraphStyle(
    name="Bodyx", fontName="Helvetica", fontSize=9.6, leading=14.2, textColor=INK,
    spaceAfter=2.3 * mm,
))
styles.add(ParagraphStyle(
    name="Smallx", fontName="Helvetica", fontSize=8.2, leading=11.5, textColor=MUTED,
    spaceAfter=1.5 * mm,
))
styles.add(ParagraphStyle(
    name="StepTitle", fontName="Helvetica-Bold", fontSize=10, leading=13, textColor=INK,
    spaceAfter=1 * mm,
))
styles.add(ParagraphStyle(
    name="TableHeader", fontName="Helvetica-Bold", fontSize=10, leading=13, textColor=colors.white,
))
styles.add(ParagraphStyle(
    name="NumberWhite", fontName="Helvetica-Bold", fontSize=10, leading=13, textColor=colors.white,
    alignment=TA_CENTER,
))
styles.add(ParagraphStyle(
    name="LabelWhite", fontName="Helvetica-Bold", fontSize=8.8, leading=11, textColor=colors.white,
    alignment=TA_CENTER,
))
styles.add(ParagraphStyle(
    name="StepBody", fontName="Helvetica", fontSize=8.8, leading=12.5, textColor=INK,
))
styles.add(ParagraphStyle(
    name="Callout", fontName="Helvetica-Bold", fontSize=10.3, leading=14.5, textColor=INK,
    leftIndent=2 * mm, rightIndent=2 * mm,
))
styles.add(ParagraphStyle(
    name="FooterNote", fontName="Helvetica", fontSize=8, leading=11, textColor=MUTED,
    alignment=TA_CENTER,
))


def p(text, style="Bodyx"):
    return Paragraph(text, styles[style])


def rule_card(content, background=CARD, border=LINE, padding=8):
    table = Table([[content]], colWidths=[174 * mm])
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), background),
        ("BOX", (0, 0), (-1, -1), 0.8, border),
        ("LEFTPADDING", (0, 0), (-1, -1), padding),
        ("RIGHTPADDING", (0, 0), (-1, -1), padding),
        ("TOPPADDING", (0, 0), (-1, -1), padding),
        ("BOTTOMPADDING", (0, 0), (-1, -1), padding),
    ]))
    return table


def callout(title, body, background=PALE_GREEN, border=GREEN):
    return rule_card(p(f"<b>{title}</b><br/>{body}", "Callout"), background, border, 10)


def step(number, title, body):
    number_cell = Table([[p(str(number), "NumberWhite")]], colWidths=[11 * mm], rowHeights=[11 * mm])
    number_cell.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), INK),
        ("TEXTCOLOR", (0, 0), (-1, -1), colors.white),
        ("ALIGN", (0, 0), (-1, -1), "CENTER"),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("BOX", (0, 0), (-1, -1), 0, INK),
    ]))
    text = [p(title, "StepTitle"), p(body, "StepBody")]
    table = Table([[number_cell, text]], colWidths=[15 * mm, 155 * mm])
    table.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("BACKGROUND", (0, 0), (-1, -1), CARD),
        ("BOX", (0, 0), (-1, -1), 0.6, LINE),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
    ]))
    return KeepTogether([table, Spacer(1, 2.3 * mm)])


def checklist(items):
    rows = []
    for item in items:
        rows.append([p("[ ]", "StepTitle"), p(item, "StepBody")])
    table = Table(rows, colWidths=[12 * mm, 158 * mm], repeatRows=0)
    table.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("BACKGROUND", (0, 0), (-1, -1), CARD),
        ("BOX", (0, 0), (-1, -1), 0.6, LINE),
        ("INNERGRID", (0, 0), (-1, -1), 0.35, LINE),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return table


def section_title(kicker, title, intro=None):
    result = [p(kicker.upper(), "Smallx"), p(title, "H1x")]
    if intro:
        result.append(p(intro, "Bodyx"))
    return result


def build_story():
    story = []
    story.extend([
        Spacer(1, 8 * mm),
        p("DOUGH BOSS.", "CoverBrand"),
        p("STAFF CLOCK", "CoverTitle"),
        p("Setup, badge printing, daily use and manager guide", "CoverSubtitle"),
        Spacer(1, 6 * mm),
        callout(
            "TODAY'S OPERATING MODEL",
            "Personal QR badge + private PIN + exact shop assignment. The kiosk clears the badge session after every action.",
            PALE_GREEN,
            GREEN,
        ),
        Spacer(1, 7 * mm),
        p("For managers, staff and the person setting up the kiosk and printer.", "CoverSubtitle"),
        Spacer(1, 4 * mm),
        p("Staff clock: doughboss.com.au/staff-clock/", "CoverSubtitle"),
        Spacer(1, 35 * mm),
        p("Secure QR and PIN attendance - DoughBoss 2.41.1", "FooterNote"),
        PageBreak(),
    ])

    story.extend(section_title(
        "01 - Understand the clock",
        "WHAT IT RECORDS",
        "The system records attendance evidence for one staff identity at one real shop. It does not make payroll policy decisions.",
    ))
    story.append(callout(
        "CURRENT SCOPE",
        "The live attendance shop currently configured in DoughBoss is Revesby. Never assign a worker to Revesby merely to enable their badge if they actually work elsewhere.",
        PALE_AMBER,
        AMBER,
    ))
    story.append(Spacer(1, 4 * mm))
    data = [
        [p("RECORDED", "TableHeader"), p("NOT RECORDED OR DECIDED", "TableHeader")],
        [p("Employee account and assigned shop", "StepBody"), p("GPS, browser location or continuous tracking in attendance tables", "StepBody")],
        [p("Clock-in and clock-out timestamps", "StepBody"), p("Automatic or assumed meal breaks", "StepBody")],
        [p("Breaks actually started and ended", "StepBody"), p("Whether a break is paid", "StepBody")],
        [p("Worked minutes after recorded breaks", "StepBody"), p("Overtime or final payroll approval", "StepBody")],
        [p("Roster start and grace snapshot", "StepBody"), p("A replacement for management review", "StepBody")],
    ]
    table = Table(data, colWidths=[85 * mm, 85 * mm], repeatRows=1)
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), INK),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("BACKGROUND", (0, 1), (0, -1), PALE_GREEN),
        ("BACKGROUND", (1, 1), (1, -1), PALE_RED),
        ("BOX", (0, 0), (-1, -1), 0.8, LINE),
        ("INNERGRID", (0, 0), (-1, -1), 0.35, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
    ]))
    story.extend([Spacer(1, 5 * mm), table, Spacer(1, 6 * mm)])
    story.append(p("Security rules", "H2x"))
    story.append(checklist([
        "One individual staff account per employee - never a shared identity.",
        "One current personal badge per employee; reissuing revokes the earlier badge.",
        "New badges use a 6-8 digit PIN. The PIN is private and is never printed on the badge.",
        "Five incorrect PIN attempts lock that badge for 15 minutes.",
        "The badge session clears after each clock or break action.",
    ]))
    story.append(PageBreak())

    story.extend(section_title(
        "02 - Manager setup",
        "SET UP EACH EMPLOYEE",
        "Complete these steps before issuing a badge. A badge must not be used to work around a missing or incorrect shop assignment.",
    ))
    manager_steps = [
        ("Create the account", "In WordPress, open <b>Users</b> and create or edit the employee's individual account."),
        ("Choose the role", "Select <b>DoughBoss Staff</b>. Use Kitchen or Manager only when that person genuinely needs the extra permissions."),
        ("Assign the real shop", "Under <b>DoughBoss staff assignment</b>, choose the employee's exact active shop."),
        ("Set the roster", "Enter start times and grace minutes only for days the person is rostered. Leave other days blank."),
        ("Open badge management", "Go to <b>DoughBoss &gt; Staff QR badges</b> and select the employee."),
        ("Choose a private PIN", "Enter a personal 6-8 digit PIN. Do not reuse a team PIN or write it on the badge."),
        ("Create once", "Select <b>Create secure QR badge</b>. The QR bearer link appears only on the issue page."),
        ("Print and separate", "Print or save the badge immediately. Give the card and PIN privately and separately."),
    ]
    for idx, (title, body) in enumerate(manager_steps, 1):
        story.append(step(idx, title, body))
    story.append(callout(
        "LOST OR COMPROMISED BADGE",
        "Revoke it in Staff QR badges. Reissue only after confirming the employee. The previous badge becomes invalid immediately.",
        PALE_RED,
        RED,
    ))
    story.append(PageBreak())

    story.extend(section_title(
        "03 - Printer and scanner",
        "PRINT A BADGE THAT SCANS",
        "Use the one-time badge page's Print / save as PDF button. Never leave the page until the card has been printed or saved and tested.",
    ))
    print_steps = [
        ("Select the printer", "Choose the office, label or card printer. Use portrait orientation."),
        ("Keep full scale", "Use 100 percent scale where possible. Keep the QR square, complete and uncropped."),
        ("Use high contrast", "Print black on white at high quality. Avoid dark, patterned or glossy stock."),
        ("Keep the PIN separate", "The PIN does not belong on the badge or in the QR printout."),
        ("Protect without glare", "Trim only outside the QR area. If laminating or using a holder, check that reflections do not block scanning."),
        ("Test the final card", "Scan the actual printed card at the real kiosk before giving it to the employee."),
    ]
    for idx, (title, body) in enumerate(print_steps, 1):
        story.append(step(idx, title, body))
    story.append(Spacer(1, 2 * mm))
    story.append(p("USB 2D scanner setup", "H2x"))
    story.append(rule_card([
        p("1. Plug the scanner into the kiosk PC.", "Bodyx"),
        p("2. Configure keyboard or HID mode.", "Bodyx"),
        p("3. Enable an Enter suffix after each scan.", "Bodyx"),
        p("4. Open the Staff Clock scan field and test the printed card.", "Bodyx"),
        p("5. If it does not advance automatically, press Enter manually and correct the scanner configuration before launch.", "Bodyx"),
    ]))
    story.append(Spacer(1, 4 * mm))
    story.append(callout(
        "PRINTER CHOICE",
        "A normal A4 laser or inkjet printer is acceptable. A label or card printer is optional. Scan reliability matters more than printer type.",
        PALE_GREEN,
        GREEN,
    ))
    story.append(PageBreak())

    story.extend(section_title(
        "04 - Kiosk and daily use",
        "ONE PERSON. ONE BADGE. ONE ACTION.",
        "Use a dedicated browser profile for the clock. Keep manager and kitchen sessions in separate profiles.",
    ))
    story.append(p("Kiosk setup", "H2x"))
    story.append(checklist([
        "Create a Chrome or Edge profile named DoughBoss Staff Clock.",
        "Open doughboss.com.au/staff-clock/ and use full-screen mode.",
        "Do not save WordPress or manager passwords in the kiosk profile.",
        "Use Ethernet where practical and keep the device powered during trade.",
        "For touch, connect both the display cable and the monitor's USB data cable.",
        "Use 1920 x 1080 at 100 percent scale where supported.",
    ]))
    story.append(Spacer(1, 5 * mm))
    story.append(p("Every shift", "H2x"))
    daily = [
        ("Scan", "Scan your own personal QR badge."),
        ("Verify", "Enter your private PIN on the large number pad."),
        ("Act", "Select the single action shown: Clock in, Start break, End break or Clock out."),
        ("Confirm", "Wait for the success message. Do not walk away before confirmation."),
        ("Clear", "The session clears. The next employee must scan their own badge."),
    ]
    for idx, (title, body) in enumerate(daily, 1):
        story.append(step(idx, title, body))
    story.append(callout(
        "DO NOT SHARE",
        "Never clock for another person, borrow a badge, share a PIN or use a real employee's badge for training.",
        PALE_RED,
        RED,
    ))
    story.append(PageBreak())

    story.extend(section_title(
        "05 - Manager review",
        "TIMESHEETS, CORRECTIONS AND PAYROLL",
        "Open DoughBoss > Staff Timesheet. Active shifts appear first. Filter by period or shop, then export CSV for review.",
    ))
    story.append(callout(
        "A TIMESHEET IS EVIDENCE, NOT FINAL PAYROLL",
        "Worked time subtracts only recorded breaks. Management still owns break policy, overtime, exceptions and payroll approval.",
        PALE_AMBER,
        AMBER,
    ))
    story.append(Spacer(1, 5 * mm))
    review_steps = [
        ("Review active shifts", "Check for anyone still clocked in and investigate before payroll is prepared."),
        ("Check the shop", "Confirm the recorded shop matches where the employee actually worked."),
        ("Check breaks", "Only actual start/end break events reduce worked minutes."),
        ("Correct with evidence", "If a shift is wrong, confirm the correct information. Never guess a time."),
        ("Give a clear reason", "Every manager correction needs a meaningful reason and remains in the audit trail."),
        ("Export and approve", "Export CSV, review exceptions, and keep final payroll approval with an authorised manager."),
    ]
    for idx, (title, body) in enumerate(review_steps, 1):
        story.append(step(idx, title, body))
    story.append(p("Suggested correction reasons", "H2x"))
    reasons = Table([
        [p("GOOD", "StepTitle"), p("Employee confirmed they forgot to clock out after the 2:30 pm close; manager verified against the closing roster.", "StepBody")],
        [p("POOR", "StepTitle"), p("Fixing it", "StepBody")],
    ], colWidths=[25 * mm, 145 * mm])
    reasons.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (0, 0), PALE_GREEN),
        ("BACKGROUND", (0, 1), (0, 1), PALE_RED),
        ("BOX", (0, 0), (-1, -1), 0.6, LINE),
        ("INNERGRID", (0, 0), (-1, -1), 0.35, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
    ]))
    story.append(reasons)
    story.append(PageBreak())

    story.extend(section_title(
        "06 - Quick fixes",
        "TROUBLESHOOTING",
        "Do not work around a clock problem by sharing accounts, issuing duplicate badges or assigning the wrong shop.",
    ))
    issues = [
        ("Nothing after scanning", "Click the scan field, scan again, then press Enter. Confirm the scanner sends an Enter suffix."),
        ("Badge not accepted", "Use the employee's current badge. An old badge stops working as soon as a replacement is issued."),
        ("PIN locked", "Wait 15 minutes. If compromise is possible, a manager verifies the employee and reissues the badge."),
        ("No active shop", "A manager must assign the person's real active shop. Do not select Revesby as a workaround."),
        ("Wrong action or break", "Stop and tell a manager. Preserve the evidence and use the approved correction process."),
        ("Kiosk offline", "Use the approved outage record, note the real time, and reconcile later with a manager reason."),
        ("Printed QR will not scan", "Reprint black on white, high quality, full and uncropped. Remove glare and test again."),
        ("Touch does not work", "Confirm the monitor's USB data/upstream cable is connected in addition to the display cable."),
    ]
    for idx, (title, body) in enumerate(issues, 1):
        story.append(step(idx, title, body))
    story.append(callout(
        "WHEN TO STOP",
        "If the identity, shop, time or action is uncertain, stop and ask a manager. Accurate attendance is more important than forcing the screen forward.",
        PALE_RED,
        RED,
    ))
    story.append(PageBreak())

    story.extend(section_title(
        "07 - Launch acceptance",
        "PROVE THE COMPLETE FLOW",
        "Complete this checklist on the real kiosk, scanner and printer before staff rely on the system.",
    ))
    story.append(checklist([
        "Every enabled employee has one individual staff account.",
        "Every enabled employee is assigned to their real active shop.",
        "Roster start and grace rules are approved.",
        "Paid/unpaid break rules are approved and communicated.",
        "Each badge is printed once and each PIN is delivered separately.",
        "Lost and superseded badges are revoked.",
        "A controlled badge scans successfully from the final printed card.",
        "The controlled user enters the PIN and clocks in at the correct shop.",
        "The same user starts and ends a break, then clocks out.",
        "The shift, break and worked time appear correctly in Staff Timesheet.",
        "The CSV export contains the same shift and neutralises unsafe spreadsheet cells.",
        "The scanner, touch screen, network and dedicated browser profile work together.",
        "Staff know the outage and correction contact process.",
    ]))
    story.append(Spacer(1, 7 * mm))
    signoff = Table([
        [p("MANAGER", "LabelWhite"), p("", "Bodyx"), p("DATE", "LabelWhite"), p("", "Bodyx")],
        [p("TEST STAFF", "LabelWhite"), p("", "Bodyx"), p("KIOSK", "LabelWhite"), p("", "Bodyx")],
        [p("RESULT", "LabelWhite"), p("[ ] PASS     [ ] NEEDS ACTION", "Bodyx"), p("RETEST", "LabelWhite"), p("", "Bodyx")],
    ], colWidths=[24 * mm, 65 * mm, 20 * mm, 61 * mm], rowHeights=[14 * mm] * 3)
    signoff.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (0, -1), INK),
        ("BACKGROUND", (2, 0), (2, -1), INK),
        ("TEXTCOLOR", (0, 0), (0, -1), colors.white),
        ("TEXTCOLOR", (2, 0), (2, -1), colors.white),
        ("BOX", (0, 0), (-1, -1), 0.8, LINE),
        ("INNERGRID", (0, 0), (-1, -1), 0.35, LINE),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
    ]))
    story.append(signoff)
    story.append(Spacer(1, 9 * mm))
    story.append(callout(
        "READY MEANS PHYSICALLY TESTED",
        "Code tests and a visible web page are necessary, but the clock is operational only after the final printed badge, scanner, kiosk, shift actions and timesheet have passed together.",
        PALE_GREEN,
        GREEN,
    ))
    return story


def main():
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    doc = DoughBossGuide(str(OUTPUT))
    doc.build(build_story())
    print(OUTPUT)


if __name__ == "__main__":
    main()
