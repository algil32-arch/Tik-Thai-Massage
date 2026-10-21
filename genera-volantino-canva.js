#!/usr/bin/env node
"use strict";

const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const { spawnSync } = require("node:child_process");

const root = __dirname;
const outputPath = path.join(root, "volantino-a6-canva.pptx");
const logoPath = path.join(root, "thai-tik-logo-alternativo.png");
const mm = value => Math.round(value / 25.4 * 914400);
const esc = value => String(value).replace(/[<>&'"]/g, ch => ({
  "<": "&lt;", ">": "&gt;", "&": "&amp;", "'": "&apos;", '"': "&quot;",
}[ch]));
const emuColor = value => value.replace("#", "").toUpperCase();
const xmlHeader = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

function runLength(value) {
  return `<a:r><a:rPr lang="it-IT" sz="${value.size || 1000}" b="${value.bold ? 1 : 0}" dirty="0"><a:solidFill><a:srgbClr val="${emuColor(value.color || "#30251f")}"/></a:solidFill><a:latin typeface="${esc(value.font || "Arial")}"/></a:rPr><a:t xml:space="preserve">${esc(value.text)}</a:t></a:r>`;
}

function paragraph(value = {}) {
  const lines = Array.isArray(value) ? value : [value];
  const first = lines[0] || {};
  return `<a:p><a:pPr algn="${first.align || "l"}" marL="0" indent="0"><a:lnSpc><a:spcPct val="100000"/></a:lnSpc><a:spcAft><a:spcPts val="0"/></a:spcAft></a:pPr>${lines.map(runLength).join("")}<a:endParaRPr lang="it-IT" sz="${first.size || 1000}"/></a:p>`;
}

function makeSlide(page, slideNumber) {
  let nextId = 2;
  const items = [];
  const addRect = (x, y, width, height, options = {}) => {
    const id = nextId++;
    const fill = options.fill
      ? `<a:solidFill><a:srgbClr val="${emuColor(options.fill)}"/></a:solidFill>`
      : "<a:noFill/>";
    const line = options.line
      ? `<a:ln w="${options.lineWidth || 12700}"><a:solidFill><a:srgbClr val="${emuColor(options.line)}"/></a:solidFill></a:ln>`
      : "<a:ln><a:noFill/></a:ln>";
    const geometry = options.ellipse ? "ellipse" : "rect";
    items.push(`<p:sp><p:nvSpPr><p:cNvPr id="${id}" name="${esc(options.name || `Elemento ${id}`)}"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="${mm(x)}" y="${mm(y)}"/><a:ext cx="${mm(width)}" cy="${mm(height)}"/></a:xfrm><a:prstGeom prst="${geometry}"><a:avLst/></a:prstGeom>${fill}${line}</p:spPr></p:sp>`);
  };
  const addText = (x, y, width, height, value, options = {}) => {
    const id = nextId++;
    const lines = Array.isArray(value) ? value : String(value).split("\n").map(text => ({ text }));
    const fill = options.fill
      ? `<a:solidFill><a:srgbClr val="${emuColor(options.fill)}"/></a:solidFill>`
      : "<a:noFill/>";
    const line = options.line
      ? `<a:ln w="${options.lineWidth || 12700}"><a:solidFill><a:srgbClr val="${emuColor(options.line)}"/></a:solidFill></a:ln>`
      : "<a:ln><a:noFill/></a:ln>";
    const inset = mm(options.insetMm || 0);
    items.push(`<p:sp><p:nvSpPr><p:cNvPr id="${id}" name="${esc(options.name || `Testo modificabile ${id}`)}"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="${mm(x)}" y="${mm(y)}"/><a:ext cx="${mm(width)}" cy="${mm(height)}"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom>${fill}${line}</p:spPr><p:txBody><a:bodyPr wrap="square" lIns="${inset}" rIns="${inset}" tIns="${inset}" bIns="${inset}" anchor="${options.valign || "ctr"}"/><a:lstStyle/>${lines.map(line => paragraph(Array.isArray(line) ? line : { text: line.text, size: line.size || options.size || 1000, color: line.color || options.color || "#30251f", bold: line.bold || options.bold, font: line.font || options.font || "Arial", align: line.align || options.align || "l" })).join("")}</p:txBody></p:sp>`);
  };
  const addLogo = () => {
    const id = nextId++;
    items.push(`<p:pic><p:nvPicPr><p:cNvPr id="${id}" name="Logo Thai Tik Massage"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill><p:spPr><a:xfrm><a:off x="${mm(39.5)}" y="${mm(4)}"/><a:ext cx="${mm(26)}" cy="${mm(26)}"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></p:spPr></p:pic>`);
  };

  addRect(0, 0, 105, 148, { fill: "#fffdf8", name: "Sfondo crema" });
  addRect(77, -17, 47, 47, { ellipse: true, line: "#eee6d8", lineWidth: 12000, name: "Decorazione circolare" });
  addRect(82, -12, 37, 37, { ellipse: true, line: "#f3ede3", lineWidth: 9000, name: "Decorazione circolare interna" });

  if (slideNumber === 1) {
    addLogo();
    addText(8, 30, 89, 5, [{ text: "TRADIZIONE THAILANDESE  ·  ROMA", size: 690, bold: true, color: "#79432f", align: "ctr" }], { align: "ctr", name: "Sottotitolo" });
    addText(8, 37, 89, 17, [
      { text: "Il tuo momento", size: 2150, font: "Georgia", color: "#30251f", align: "ctr" },
      { text: "di benessere.", size: 2150, font: "Georgia", color: "#30251f", align: "ctr" },
    ], { align: "ctr", name: "Titolo promozionale" });
    addText(10, 56, 85, 13, [
      { text: "Rallenta, respira, ritrova il tuo equilibrio", size: 900, color: "#71645c", align: "ctr" },
      { text: "con un trattamento scelto intorno a te.", size: 900, color: "#71645c", align: "ctr" },
    ], { align: "ctr", name: "Descrizione" });
    addRect(8, 72, 89, 0.6, { fill: "#b48a4d", name: "Linea decorativa" });

    const treatments = [
      ["Thai tradizionale", "Deep Tissue sportivo"],
      ["Oli e aromaterapia", "Tok Sen Massage"],
      ["Foot Massage", ""],
    ];
    treatments.forEach((row, index) => {
      const y = 77 + index * 12;
      row.forEach((label, column) => {
        if (!label) return;
        const x = column === 0 ? 8 : 54;
        addRect(x, y, 43, 9.5, { fill: "#f4eee5", line: "#e4d9ca", name: `Riquadro ${label}` });
        addText(x + 2, y + 1, 39, 7.5, [{ text: `•  ${label}`, size: 690, bold: true, align: "l" }], { insetMm: 0.5, name: `Trattamento ${label}` });
      });
    });

    addRect(8, 116, 89, 18, { fill: "#29463b", name: "Invito a prenotare" });
    addText(10, 118, 85, 6, [{ text: "Concediti una pausa.", size: 1300, font: "Georgia", color: "#fffdf8", align: "ctr" }], { align: "ctr", name: "Messaggio promozionale" });
    addText(11, 125, 83, 7, [
      { text: "Su appuntamento a Prati e Monte Sacro.", size: 740, color: "#fffdf8", align: "ctr" },
      { text: "Scegli il trattamento e prenota il tuo momento.", size: 700, color: "#fffdf8", align: "ctr" },
    ], { align: "ctr", name: "Sedi su appuntamento" });
    addText(8, 137, 89, 9, [
      { text: "PRENOTA ONLINE  ·  thaitikmassage.it", size: 710, bold: true, align: "ctr" },
      { text: "WhatsApp: wa.me/message/4ZL65LROJWHFI1", size: 650, align: "ctr" },
      { text: "info@thaitikmassage.it", size: 700, bold: true, align: "ctr" },
    ], { align: "ctr", name: "Contatti per prenotazione" });
  } else {
    addText(8, 7, 89, 5, [{ text: "THAI TIK MASSAGE  ·  ROMA", size: 680, bold: true, color: "#79432f", align: "l" }], { name: "Marchio" });
    addText(8, 13, 89, 8, [{ text: "Scegli il tuo trattamento", size: 1500, font: "Georgia", color: "#79432f", align: "l" }], { name: "Titolo listino" });

    const prices = [
      ["Thai tradizionale · 60 min", "60 €"],
      ["Thai tradizionale · 90 min", "80 €"],
      ["Sportivo Deep Tissue · 60 min", "60 €"],
      ["Oli e aromaterapia · 60 min", "60 €"],
      ["Oli e aromaterapia · 90 min", "80 €"],
      ["Oli e aromaterapia · 120 min", "100 €"],
      ["Tok Sen Massage · 60 min", "70 €"],
      ["Foot Massage Express · 30 min", "35 €"],
      ["Foot Massage Completo · 60 min", "50 €"],
    ];
    prices.forEach(([label, price], index) => {
      const y = 22 + index * 5.1;
      if (index % 2 === 1) addRect(8, y, 89, 5.05, { fill: "#f4eee5", name: `Riga ${label}` });
      addText(10, y + 0.15, 70, 4.5, [{ text: label, size: 690 }], { name: `Prezzo ${label}` });
      addText(79, y + 0.15, 16, 4.5, [{ text: price, size: 720, bold: true, color: "#79432f", align: "r" }], { align: "r", name: `Importo ${label}` });
      addRect(8, y + 5, 89, 0.18, { fill: "#e4d9ca", name: "Separatore prezzi" });
    });

    addText(8, 70, 89, 8, [{ text: "Due sedi a Roma", size: 1500, font: "Georgia", color: "#79432f", align: "l" }], { name: "Titolo sedi" });
    const studios = [
      { x: 8, title: "PRATI · Barbiere Prati", lines: ["Via Giuseppe Gioachino Belli 1", "00193 Roma"] },
      { x: 54, title: "Monte Sacro · Jaqueline", lines: ["Massaggi APS", "Via Valle Borbera 51", "Monte Sacro, Roma"] },
    ];
    studios.forEach(studio => {
      addRect(studio.x, 79, 43, 18, { fill: "#f4eee5", line: "#e4d9ca", name: `Sede ${studio.title}` });
      addText(studio.x + 2, 80, 39, 5, [{ text: studio.title, size: studio.title.length > 22 ? 570 : 620, bold: true, color: "#29463b" }], { name: `Nome sede ${studio.title}` });
      addText(studio.x + 2, 85.5, 39, 10, studio.lines.map(text => ({ text, size: 610 })), { valign: "t", name: `Indirizzo ${studio.title}` });
    });
    addText(8, 98, 89, 12, [
      { text: "Monte Sacro: per accedere è richiesta l’adesione ad APS Jaqueline e la quota", size: 550, color: "#71645c" },
      { text: "associativa annuale di 15 €, non inclusa nei prezzi. Verifica i dettagli", size: 550, color: "#71645c" },
      { text: "con l’associazione prima dell’appuntamento.", size: 550, color: "#71645c" },
    ], { valign: "t", name: "Nota sulla quota associativa" });

    addRect(8, 119, 89, 21, { fill: "#29463b", name: "Barra informazioni e prenotazioni" });
    addText(10, 120.5, 85, 4, [{ text: "INFORMAZIONI E PRENOTAZIONI", size: 700, bold: true, color: "#fffdf8", align: "ctr" }], { align: "ctr", name: "Contatti titolo" });
    addText(10, 125, 85, 5, [{ text: "thaitikmassage.it/prenota.html  ·  WhatsApp  ·  Email", size: 620, bold: true, color: "#fffdf8", align: "ctr" }], { align: "ctr", name: "Link prenotazione" });
    addText(10, 130, 85, 5, [{ text: "Sedi e mappe: thaitikmassage.it/dove-trovarmi.html", size: 600, bold: true, color: "#fffdf8", align: "ctr" }], { align: "ctr", name: "Link sedi" });
    addText(8, 142, 89, 4, [{ text: "Trattamenti per il benessere · Su appuntamento · Verifica sede e disponibilità", size: 510, color: "#71645c", align: "ctr" }], { align: "ctr", name: "Nota appuntamenti" });
  }

  const logoRelationship = slideNumber === 1
    ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/>'
    : "";
  return {
    xml: `${xmlHeader}<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld name="${slideNumber === 1 ? "Fronte" : "Retro"}"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>${items.join("")}</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>`,
    rels: `${xmlHeader}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>${logoRelationship}</Relationships>`,
  };
}

function generate() {
  if (!fs.existsSync(logoPath)) throw new Error("Logo aziendale non trovato: thai-tik-logo-alternativo.png");
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), "thai-tik-canva-"));
  const write = (relative, content) => {
    const target = path.join(temp, relative);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, content);
  };
  try {
    write("[Content_Types].xml", `${xmlHeader}<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/><Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/><Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/><Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/><Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/><Override PartName="/ppt/slides/slide2.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>`);
    write("_rels/.rels", `${xmlHeader}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>`);
    write("ppt/presentation.xml", `${xmlHeader}<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst><p:sldId id="256" r:id="rId2"/><p:sldId id="257" r:id="rId3"/></p:sldIdLst><p:sldSz cx="${mm(105)}" cy="${mm(148)}" type="custom"/><p:notesSz cx="${mm(148)}" cy="${mm(105)}"/><p:defaultTextStyle><a:defPPr><a:defRPr lang="it-IT"/></a:defPPr><a:lvl1pPr marL="0" indent="0"><a:defRPr lang="it-IT" sz="1000"/></a:lvl1pPr></p:defaultTextStyle></p:presentation>`);
    write("ppt/_rels/presentation.xml.rels", `${xmlHeader}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/></Relationships>`);
    write("ppt/slideMasters/slideMaster1.xml", `${xmlHeader}<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree></p:cSld><p:clrMap accent1="79432F" accent2="B48A4D" accent3="29463B" accent4="71645C" accent5="E4D9CA" accent6="F4EEE5" bg1="FFFFFF" bg2="FFFDF8" dk1="30251F" dk2="71645C" folHlink="79432F" hlink="79432F" lt1="FFFFFF" lt2="FFFDF8"/><p:sldLayoutIdLst><p:sldLayoutId id="1" r:id="rId1"/></p:sldLayoutIdLst><p:txStyles><p:titleStyle><a:lvl1pPr algn="ctr"><a:defRPr lang="it-IT" sz="2200" b="0"><a:latin typeface="Georgia"/></a:defRPr></a:lvl1pPr></p:titleStyle><p:bodyStyle><a:lvl1pPr><a:defRPr lang="it-IT" sz="1000"><a:latin typeface="Arial"/></a:defRPr></a:lvl1pPr></p:bodyStyle><p:otherStyle><a:lvl1pPr><a:defRPr lang="it-IT" sz="1000"><a:latin typeface="Arial"/></a:defRPr></a:lvl1pPr></p:otherStyle></p:txStyles></p:sldMaster>`);
    write("ppt/slideMasters/_rels/slideMaster1.xml.rels", `${xmlHeader}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/></Relationships>`);
    write("ppt/slideLayouts/slideLayout1.xml", `${xmlHeader}<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1"><p:cSld name="Layout vuoto"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>`);
    write("ppt/slideLayouts/_rels/slideLayout1.xml.rels", `${xmlHeader}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/></Relationships>`);
    write("ppt/theme/theme1.xml", `${xmlHeader}<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Thai Tik"><a:themeElements><a:clrScheme name="Thai Tik"><a:dk1><a:srgbClr val="30251F"/></a:dk1><a:lt1><a:srgbClr val="FFFDF8"/></a:lt1><a:dk2><a:srgbClr val="71645C"/></a:dk2><a:lt2><a:srgbClr val="F4EEE5"/></a:lt2><a:accent1><a:srgbClr val="79432F"/></a:accent1><a:accent2><a:srgbClr val="B48A4D"/></a:accent2><a:accent3><a:srgbClr val="29463B"/></a:accent3><a:accent4><a:srgbClr val="E4D9CA"/></a:accent4><a:accent5><a:srgbClr val="71645C"/></a:accent5><a:accent6><a:srgbClr val="FFFFFF"/></a:accent6><a:hlink><a:srgbClr val="79432F"/></a:hlink><a:folHlink><a:srgbClr val="79432F"/></a:folHlink></a:clrScheme><a:fontScheme name="Thai Tik"><a:majorFont><a:latin typeface="Georgia"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Arial"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme><a:fmtScheme name="Thai Tik"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst><a:lnStyleLst><a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst><a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst><a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme></a:themeElements></a:theme>`);
    write("docProps/core.xml", `${xmlHeader}<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Volantino Thai Tik Massage A6 per Canva</dc:title><dc:subject>Volantino promozionale fronte-retro A6</dc:subject><dc:language>it-IT</dc:language></cp:coreProperties>`);
    write("docProps/app.xml", `${xmlHeader}<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Thai Tik Massage</Application><PresentationFormat>A6 portrait</PresentationFormat><Slides>2</Slides></Properties>`);
    const front = makeSlide({}, 1);
    const back = makeSlide({}, 2);
    write("ppt/slides/slide1.xml", front.xml);
    write("ppt/slides/_rels/slide1.xml.rels", front.rels);
    write("ppt/slides/slide2.xml", back.xml);
    write("ppt/slides/_rels/slide2.xml.rels", back.rels);
    fs.mkdirSync(path.join(temp, "ppt/media"), { recursive: true });
    fs.copyFileSync(logoPath, path.join(temp, "ppt/media/logo.png"));
    const result = spawnSync("zip", ["-q", "-X", "-r", outputPath, "."], { cwd: temp, encoding: "utf8" });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(result.stderr || "Creazione archivio PPTX non riuscita.");
    console.log(`Creato ${path.basename(outputPath)} (${(fs.statSync(outputPath).size / 1024).toFixed(1)} KB).`);
  } finally {
    fs.rmSync(temp, { recursive: true, force: true });
  }
}

generate();
