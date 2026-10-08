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
    canvas.setFillColor(colors.HexColor('#250c05'))
    canvas.rect(0, HEIGHT - 38 * mm, WIDTH, 38 * mm, stroke=0, fill=1)
    canvas.setFillColor(colors.HexColor('#c8a44d'))
    canvas.rect(0, HEIGHT - 38 * mm, WIDTH, 4, stroke=0, fill=1)

    logo = ImageReader('tik-thai-logo-transparent.png')
    canvas.drawImage(logo, 16 * mm, HEIGHT - 31 * mm, width=24 * mm, height=24 * mm, mask='auto')

    canvas.setFillColor(colors.white)
    canvas.setFont('Times-Bold', 18)
    canvas.drawString(43 * mm, HEIGHT - 22 * mm, 'THAI TIK MASSAGE')
    canvas.setFont('Times-Roman', 9)
    canvas.setFillColor(colors.HexColor('#f1e4bd'))
    canvas.drawString(43 * mm, HEIGHT - 27 * mm, '& WELLNESS')

    canvas.restoreState()


def field_label(it, en):
    return (
        '<para align="center">'
        '<font name="Helvetica-Bold" size="7.5">' + it + '</font><br/><br/>'
        '<font name="Helvetica" size="6.5" color="#716862">' + en + '</font><br/><br/>'
        '<font name="Helvetica" size="7">...........................................................</font>'
        '</para>'
    )


def build_pdf(output_path):
    styles = getSampleStyleSheet()
    title_style = ParagraphStyle(
        'title',
        parent=styles['Title'],
        fontName='Helvetica-Bold',
        fontSize=18,
        leading=22,
        textColor=colors.HexColor('#2a201d'),
        alignment=TA_CENTER,
        spaceAfter=6,
    )
    subtitle_style = ParagraphStyle(
        'subtitle',
        parent=styles['BodyText'],
        fontName='Helvetica',
        fontSize=8,
        leading=10,
        textColor=colors.HexColor('#716862'),
        alignment=TA_CENTER,
        spaceAfter=10,
    )

    story = []
    story.append(Spacer(1, 34 * mm))
    story.append(Paragraph('Scheda dati cliente per fatturazione<br/>Customer billing information', title_style))
    story.append(Paragraph('Compilare in negozio se i dati non sono stati inseriti online<br/>Complete in-store if details were not entered online', subtitle_style))

    rows = [
        (
            field_label('Ragione sociale / Nome e cognome', 'Company name / Full name'),
            field_label('Codice fiscale / Tax code', 'VAT / Fiscal code'),
        ),
        (
            field_label('Partita IVA / VAT number', 'Tax ID / VAT number'),
            field_label('Telefono / Phone', 'Mobile / Telephone'),
        ),
        (
            field_label('Indirizzo / Address', 'Street address'),
            field_label('CAP / Postcode', 'ZIP / Postal code'),
        ),
        (
            field_label('Città / City', 'City / Town'),
            field_label('Provincia / Province', 'State / Region'),
        ),
        (
            field_label('Email / Email', 'Email address'),
            field_label('Nazione / Country', 'Country'),
        ),
    ]

    data_table = Table(rows, colWidths=[81 * mm, 81 * mm], rowHeights=[22 * mm] * len(rows))
    data_table.setStyle(
        TableStyle([
            ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#fffdfb')),
            ('GRID', (0, 0), (-1, -1), 0.8, colors.HexColor('#ded5cb')),
            ('VALIGN', (0, 0), (-1, -1), 'TOP'),
            ('LEFTPADDING', (0, 0), (-1, -1), 6),
            ('RIGHTPADDING', (0, 0), (-1, -1), 6),
            ('TOPPADDING', (0, 0), (-1, -1), 2),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 2),
        ])
    )
    story.append(data_table)
    story.append(Spacer(1, 6 * mm))

    summary_rows = [
        (
            field_label('Servizio / Treatment', 'Service / Treatment'),
            field_label('Data / Date', 'Appointment date'),
        ),
        (
            field_label('Importo / Amount', 'Total amount'),
            field_label('Metodo di pagamento / Payment method', 'Payment method'),
        ),
        (
            field_label('Note / Notes', 'Additional notes'),
            field_label('Firma cliente / Customer signature', 'Client signature'),
        ),
    ]

    summary_table = Table(summary_rows, colWidths=[81 * mm, 81 * mm], rowHeights=[26 * mm, 26 * mm, 28 * mm])
    summary_table.setStyle(
        TableStyle([
            ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#fffdfb')),
            ('GRID', (0, 0), (-1, -1), 0.8, colors.HexColor('#ded5cb')),
            ('VALIGN', (0, 0), (-1, -1), 'TOP'),
            ('LEFTPADDING', (0, 0), (-1, -1), 6),
            ('RIGHTPADDING', (0, 0), (-1, -1), 6),
            ('TOPPADDING', (0, 0), (-1, -1), 2),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 2),
        ])
    )
    story.append(summary_table)
    story.append(Spacer(1, 5 * mm))
    story.append(
        Paragraph(
            'I dati raccolti saranno usati esclusivamente per la fatturazione e la gestione del servizio.<br/>'
            'The information collected will be used exclusively for invoicing and service management.',
            ParagraphStyle('final', parent=styles['BodyText'], fontName='Helvetica', fontSize=7, leading=10, textColor=colors.HexColor('#716862'))
        )
    )

    doc = SimpleDocTemplate(
        output_path,
        pagesize=A4,
        leftMargin=15 * mm,
        rightMargin=15 * mm,
        topMargin=10 * mm,
        bottomMargin=12 * mm,
        title='Scheda cliente fatturazione bilingue - Thai Tik Massage',
    )
    doc.build(story, onFirstPage=add_header, onLaterPages=add_header)


if __name__ == '__main__':
    build_pdf('cliente-dati-fatturazione-bilingue.pdf')
    print('PDF bilingue creato: cliente-dati-fatturazione-bilingue.pdf')
