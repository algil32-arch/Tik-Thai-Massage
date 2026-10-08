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
        spaceBefore=0,
        spaceAfter=10,
    )
    label_style = ParagraphStyle(
        'label',
        parent=styles['BodyText'],
        fontName='Helvetica-Bold',
        fontSize=8,
        leading=18,
        textColor=colors.HexColor('#2a201d'),
        alignment=TA_CENTER,
        spaceBefore=0,
        spaceAfter=0,
    )

    story = []
    story.append(Spacer(1, 36 * mm))
    story.append(Paragraph('Scheda dati cliente per fatturazione', title_style))
    story.append(Spacer(1, 3 * mm))

    fields = [
        ['Ragione sociale / Nome e cognome', 'Codice fiscale'],
        ['Partita IVA', 'Telefono'],
        ['Indirizzo', 'CAP'],
        ['Città', 'Provincia'],
        ['Email', 'Nazione'],
    ]

    table_rows = []
    for left, right in fields:
        row = [
            Paragraph(
                f'<para align="center"><font name="Helvetica-Bold" size="8">{left}</font><br/><br/>'
                '<font name="Helvetica" size="7">...........................................................</font></para>',
                label_style,
            ),
            Paragraph(
                f'<para align="center"><font name="Helvetica-Bold" size="8">{right}</font><br/><br/>'
                '<font name="Helvetica" size="7">...........................................................</font></para>',
                label_style,
            ),
        ]
        table_rows.append(row)

    data_table = Table(table_rows, colWidths=[81 * mm, 81 * mm], rowHeights=[22 * mm, 22 * mm, 22 * mm, 22 * mm, 22 * mm])
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

    summary = [
        ['Servizio / trattamento', 'Data'],
        ['Importo', 'Metodo di pagamento'],
        ['Note / richieste', 'Firma cliente'],
    ]

    summary_rows = []
    for left, right in summary:
        row = [
            Paragraph(
                f'<para align="center"><font name="Helvetica-Bold" size="8">{left}</font><br/><br/>'
                '<font name="Helvetica" size="7">...........................................................</font></para>',
                label_style,
            ),
            Paragraph(
                f'<para align="center"><font name="Helvetica-Bold" size="8">{right}</font><br/><br/>'
                '<font name="Helvetica" size="7">...........................................................</font></para>',
                label_style,
            ),
        ]
        summary_rows.append(row)

    summary_table = Table(summary_rows, colWidths=[81 * mm, 81 * mm], rowHeights=[24 * mm, 24 * mm, 26 * mm])
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

    footer_note = ParagraphStyle(
        'note',
        parent=styles['BodyText'],
        fontName='Helvetica',
        fontSize=7,
        leading=10,
        textColor=colors.HexColor('#716862'),
        alignment=TA_LEFT,
    )
    story.append(Spacer(1, 6 * mm))
    story.append(Paragraph('Compilare questa scheda in caso il cliente non abbia inserito i dati online. La firma autorizza l’uso dei dati per la fatturazione.', footer_note))

    doc = SimpleDocTemplate(
        output_path,
        pagesize=A4,
        leftMargin=15 * mm,
        rightMargin=15 * mm,
        topMargin=10 * mm,
        bottomMargin=12 * mm,
        title='Scheda cliente fatturazione - Thai Tik Massage',
    )
    doc.build(story, onFirstPage=add_header, onLaterPages=add_header)


if __name__ == '__main__':
    build_pdf('cliente-dati-fatturazione.pdf')
    print('PDF creato: cliente-dati-fatturazione.pdf')
