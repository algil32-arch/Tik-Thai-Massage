from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.enums import TA_CENTER
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle
from reportlab.lib.utils import ImageReader

WIDTH, HEIGHT = A4


def add_header(canvas, doc):
    canvas.saveState()
    canvas.setFillColor(colors.HexColor('#ffffff'))
    canvas.rect(0, HEIGHT - (24 * mm + 10), WIDTH, 24 * mm, stroke=0, fill=1)
    canvas.setFillColor(colors.HexColor('#c8a44d'))
    canvas.rect(0, HEIGHT - (24 * mm + 10), WIDTH, 1.2, stroke=0, fill=1)

    logo = ImageReader('tik-thai-logo-transparent.png')
    canvas.drawImage(logo, 12 * mm, HEIGHT - (21 * mm + 10), width=18 * mm, height=18 * mm, mask='auto')

    canvas.setFillColor(colors.HexColor('#250c05'))
    canvas.setFont('Times-Bold', 15)
    canvas.drawString(32 * mm, HEIGHT - (15 * mm + 10), 'THAI TIK MASSAGE')
    canvas.setFont('Times-Roman', 7)
    canvas.setFillColor(colors.HexColor('#6b5f56'))
    canvas.drawString(32 * mm, HEIGHT - (19 * mm + 10), '& WELLNESS')

    canvas.restoreState()


def cell(it, en):
    text = (
        f'<para align="center"><font name="Helvetica-Bold" size="7">{it}</font><br/>'
        f'<font name="Helvetica" size="6">{en}</font><br/><br/><br/>'
        '<font name="Helvetica" size="7">...........................................................</font></para>'
    )
    style = ParagraphStyle(
        'cell',
        fontName='Helvetica',
        fontSize=7,
        leading=10,
        alignment=1,
        textColor=colors.HexColor('#2a201d'),
        spaceAfter=0,
        spaceBefore=0,
    )
    return Paragraph(text, style)


def big_cell(it, en):
    text = (
        f'<para align="center"><font name="Helvetica-Bold" size="8">{it}</font><br/>'
        f'<font name="Helvetica" size="6.5">{en}</font><br/><br/><br/>'
        '<font name="Helvetica" size="7">...........................................................</font></para>'
    )
    style = ParagraphStyle(
        'big_cell',
        fontName='Helvetica',
        fontSize=8,
        leading=11,
        alignment=1,
        textColor=colors.HexColor('#2a201d'),
        spaceAfter=0,
        spaceBefore=0,
    )
    return Paragraph(text, style)


def build_pdf(output_path):
    styles = getSampleStyleSheet()
    title_style = ParagraphStyle(
        'title',
        parent=styles['Title'],
        fontName='Helvetica-Bold',
        fontSize=14,
        leading=18,
        textColor=colors.HexColor('#2a201d'),
        alignment=TA_CENTER,
        spaceAfter=4,
    )
    subtitle_style = ParagraphStyle(
        'subtitle',
        parent=styles['BodyText'],
        fontName='Helvetica',
        fontSize=7,
        leading=9,
        textColor=colors.HexColor('#716862'),
        alignment=TA_CENTER,
        spaceAfter=8,
    )

    story = []
    story.append(Spacer(1, 27 * mm))
    story.append(Paragraph('Scheda cliente per fatturazione<br/>Client invoice details', title_style))
    story.append(Paragraph('Compilare in negozio se i dati non sono stati inseriti online<br/>Complete in-store if details were not entered online', subtitle_style))

    rows = [
        (cell('Ragione sociale / Company name', 'Full name'), cell('Codice fiscale / Tax code', 'Fiscal code')),
        (cell('Partita IVA / VAT number', 'VAT number'), cell('Telefono / Phone', 'Phone')),
        (cell('Indirizzo / Address', 'Street address'), cell('CAP / ZIP', 'Postal code')),
        (cell('Città / City', 'City'), cell('Provincia / Province', 'Region')),
        (cell('Email / Email', 'Email'), cell('Nazione / Country', 'Country')),
    ]

    table = Table(rows, colWidths=[81 * mm, 81 * mm], rowHeights=[22 * mm] * len(rows))
    table.setStyle(
        TableStyle([
            ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#fffdfb')),
            ('GRID', (0, 0), (-1, -1), 0.8, colors.HexColor('#d7cfc7')),
            ('VALIGN', (0, 0), (-1, -1), 'TOP'),
            ('LEFTPADDING', (0, 0), (-1, -1), 6),
            ('RIGHTPADDING', (0, 0), (-1, -1), 6),
            ('TOPPADDING', (0, 0), (-1, -1), 3),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 3),
        ])
    )
    story.append(table)
    story.append(Spacer(1, 6 * mm))

    bottom_rows = [
        (cell('Servizio / Service', 'Treatment'), cell('Data / Date', 'Appointment date')),
        (cell('Importo / Amount', 'Total amount'), cell('Pagamento / Payment', 'Payment method')),
        (cell('Note / Notes', 'Additional notes'), cell('Firma / Signature', 'Customer signature')),
    ]
    bottom_table = Table(bottom_rows, colWidths=[81 * mm, 81 * mm], rowHeights=[24 * mm, 24 * mm, 28 * mm])
    bottom_table.setStyle(
        TableStyle([
            ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#fffdfb')),
            ('GRID', (0, 0), (-1, -1), 0.8, colors.HexColor('#d7cfc7')),
            ('VALIGN', (0, 0), (-1, -1), 'TOP'),
            ('LEFTPADDING', (0, 0), (-1, -1), 6),
            ('RIGHTPADDING', (0, 0), (-1, -1), 6),
            ('TOPPADDING', (0, 0), (-1, -1), 3),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 3),
        ])
    )
    story.append(bottom_table)
    story.append(Spacer(1, 4 * mm))
    story.append(
        Paragraph(
            'Compilare questa scheda in caso il cliente non abbia inserito i dati online. La firma autorizza l’uso dei dati per la fatturazione.<br/>'
            'Please complete this form if the client did not enter their details online. The signature authorizes the use of the data for invoicing.',
            ParagraphStyle(
                'footer',
                parent=styles['BodyText'],
                fontName='Helvetica',
                fontSize=7,
                leading=9,
                textColor=colors.HexColor('#716862'),
                alignment=TA_CENTER,
                spaceAfter=0,
            )
        )
    )

    doc = SimpleDocTemplate(
        output_path,
        pagesize=A4,
        leftMargin=15 * mm,
        rightMargin=15 * mm,
        topMargin=10 * mm,
        bottomMargin=12 * mm,
        title='Scheda cliente semplificata - Thai Tik Massage',
    )
    doc.build(story, onFirstPage=add_header, onLaterPages=add_header)


if __name__ == '__main__':
    build_pdf('cliente-dati-fatturazione-bilingue-minima.pdf')
    print('PDF minimale creato: cliente-dati-fatturazione-bilingue-minima.pdf')
