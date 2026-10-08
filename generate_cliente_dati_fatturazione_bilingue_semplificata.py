from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle
from reportlab.lib.utils import ImageReader

WIDTH, HEIGHT = A4


def add_header(canvas, doc):
    canvas.saveState()
    canvas.setFillColor(colors.HexColor('#250c05'))
    canvas.rect(0, HEIGHT - 24 * mm, WIDTH, 24 * mm, stroke=0, fill=1)
    canvas.setFillColor(colors.HexColor('#c8a44d'))
    canvas.rect(0, HEIGHT - 24 * mm, WIDTH, 3, stroke=0, fill=1)

    logo = ImageReader('tik-thai-logo-transparent.png')
    canvas.drawImage(logo, 12 * mm, HEIGHT - 20 * mm, width=18 * mm, height=18 * mm, mask='auto')

    canvas.setFillColor(colors.white)
    canvas.setFont('Times-Bold', 15)
    canvas.drawString(32 * mm, HEIGHT - 15 * mm, 'THAI TIK MASSAGE')
    canvas.setFont('Times-Roman', 7)
    canvas.setFillColor(colors.HexColor('#f1e4bd'))
    canvas.drawString(32 * mm, HEIGHT - 19 * mm, '& WELLNESS')

    canvas.restoreState()


def field_label(it, en):
    return (
        '<para align="center"><font name="Helvetica-Bold" size="7">' + it + '</font><br/>'
        '<font name="Helvetica" size="6.5">' + en + '</font><br/><br/>'
        '<font name="Helvetica" size="7">...........................................................</font></para>'
    )


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
    note_style = ParagraphStyle(
        'note',
        parent=styles['BodyText'],
        fontName='Helvetica',
        fontSize=6.5,
        leading=8,
        textColor=colors.HexColor('#716862'),
        alignment=TA_LEFT,
    )

    story = []
    story.append(Spacer(1, 28 * mm))
    story.append(Paragraph('Scheda dati cliente / Customer information', title_style))
    story.append(Paragraph('Compilare in negozio se i dati non sono stati inseriti online<br/>Complete in-store if details were not entered online', subtitle_style))

    rows = [
        (
            field_label('Nome e cognome / Full name', 'Customer name'),
            field_label('Codice fiscale / Tax code', 'Fiscal / tax ID'),
        ),
        (
            field_label('Partita IVA / VAT number', 'VAT number'),
            field_label('Telefono / Phone', 'Phone / mobile'),
        ),
        (
            field_label('Email / Email', 'Email address'),
            field_label('Nazione / Country', 'Country'),
        ),
        (
            field_label('Indirizzo / Address', 'Street address'),
            field_label('CAP / ZIP', 'Postal code'),
        ),
        (
            field_label('Città / City', 'Town / city'),
            field_label('Provincia / Province', 'Region / state'),
        ),
    ]

    main_table = Table(rows, colWidths=[92 * mm, 70 * mm], rowHeights=[20 * mm] * len(rows))
    main_table.setStyle(
        TableStyle([
            ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#fffdfb')),
            ('GRID', (0, 0), (-1, -1), 0.7, colors.HexColor('#d7cfc7')),
            ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
            ('LEFTPADDING', (0, 0), (-1, -1), 6),
            ('RIGHTPADDING', (0, 0), (-1, -1), 6),
            ('TOPPADDING', (0, 0), (-1, -1), 4),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 4),
        ])
    )
    story.append(main_table)
    story.append(Spacer(1, 5 * mm))

    summary_rows = [
        (
            field_label('Servizio / Treatment', 'Service / treatment'),
            field_label('Data / Date', 'Appointment date'),
        ),
        (
            field_label('Importo / Amount', 'Total amount'),
            field_label('Pagamento / Payment', 'Payment method'),
        ),
        (
            field_label('Note / Notes', 'Additional notes'),
            field_label('Firma / Signature', 'Customer signature'),
        ),
    ]

    summary_table = Table(summary_rows, colWidths=[92 * mm, 70 * mm], rowHeights=[26 * mm, 26 * mm, 30 * mm])
    summary_table.setStyle(
        TableStyle([
            ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#fffdfb')),
            ('GRID', (0, 0), (-1, -1), 0.7, colors.HexColor('#d7cfc7')),
            ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
            ('LEFTPADDING', (0, 0), (-1, -1), 6),
            ('RIGHTPADDING', (0, 0), (-1, -1), 6),
            ('TOPPADDING', (0, 0), (-1, -1), 5),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 5),
        ])
    )
    story.append(summary_table)
    story.append(Spacer(1, 4 * mm))
    story.append(Paragraph('I dati raccolti saranno usati esclusivamente per la fatturazione e la gestione del servizio.<br/>The information will be used only for invoicing and service management.', note_style))

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
    build_pdf('cliente-dati-fatturazione-bilingue-semplificata.pdf')
    print('PDF semplificato creato: cliente-dati-fatturazione-bilingue-semplificata.pdf')
