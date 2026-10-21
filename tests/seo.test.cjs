const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const sitemapPath = path.join(root, 'sitemap.xml');
const sitemap = fs.readFileSync(sitemapPath, 'utf8');
const urlPaths = [...sitemap.matchAll(/<loc>https:\/\/www\.thaitikmassage\.it(\/[^<]*)?<\/loc>/g)]
  .map(([, urlPath]) => urlPath || '/');
const origin = 'https://www.thaitikmassage.it';
const partners = [
  { file: 'centro-prati.html', id: `${origin}/#location-prati`, district: 'Prati', street: 'Via Giuseppe Gioachino Belli 1', cap: '00193', studio: 'roma' },
  { file: 'centro-monte-sacro.html', id: `${origin}/#location-monte-sacro`, district: 'Monte Sacro', street: 'Via Valle Borbera 51', cap: '00141', studio: 'studio2' }
];
const htmlFiles = fs.readdirSync(root).filter(file => file.endsWith('.html'));
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const readGraph = html => {
  const scripts = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)];
  assert.equal(scripts.length, 1, 'Each page needs one consistent structured-data graph');
  const data = JSON.parse(scripts[0][1]);
  assert.equal(data['@context'], 'https://schema.org');
  return data['@graph'];
};

test('all HTML pages define a title and meta description', () => {
  const htmlFiles = fs.readdirSync(root).filter((file) => file.endsWith('.html'));
  for (const file of htmlFiles) {
    const html = fs.readFileSync(path.join(root, file), 'utf8');
    assert.match(html, /<title>[^<]+<\/title>/i, `${file} needs a non-empty title`);
    assert.match(html, /<meta\s+name=["']description["']\s+content=["'][^"']+["']/i, `${file} needs a meta description`);
  }
});

test('public copy consistently spells the neighborhood Monte Sacro', () => {
  const files = fs.readdirSync(root).filter(file => file.endsWith('.html'));
  files.push('genera-volantino-canva.js');
  for (const file of files) {
    const source = fs.readFileSync(path.join(root, file), 'utf8');
    const copy = source.replaceAll('APS Studio Montesacro', '');
    assert.doesNotMatch(copy, /\b(?:Montesacro|MONTESACRO|monte sacro|Monte sacro)\b/,
      `${file} must use Monte Sacro in public copy, including SEO metadata`);
  }
});

test('sitemap contains only existing public HTML pages without noindex', () => {
  assert.match(sitemap, /^<\?xml version="1\.0" encoding="UTF-8"\?>/);
  assert.ok(urlPaths.length > 0, 'Sitemap should contain URLs');
  assert.equal(new Set(urlPaths).size, urlPaths.length, 'Sitemap URLs should be unique');

  for (const urlPath of urlPaths) {
    const file = urlPath === '/' ? 'index.html' : urlPath.slice(1);
    assert.ok(fs.existsSync(path.join(root, file)), `${urlPath} must exist`);
    const html = fs.readFileSync(path.join(root, file), 'utf8');
    assert.doesNotMatch(html, /<meta\s+name=["']robots["']\s+content=["'][^"']*noindex/i, `${urlPath} must be indexable`);
  }
});

test('robots file points to the sitemap and excludes non-public paths', () => {
  const robots = fs.readFileSync(path.join(root, 'robots.txt'), 'utf8');
  assert.match(robots, /Sitemap:\s+https:\/\/www\.thaitikmassage\.it\/sitemap\.xml/);
  for (const disallowedPath of ['/private/', '/tests/', '/documents/', '/fatture-admin.php']) {
    assert.ok(robots.includes(`Disallow: ${disallowedPath}`), `${disallowedPath} should be disallowed`);
  }
});

test('public pages use the canonical www domain and redirects point to their destination', () => {
  for (const file of htmlFiles) {
    const html = read(file);
    if (/<meta\s+name="robots"\s+content="[^"]*noindex/i.test(html)) continue;
    const canonicals = [...html.matchAll(/<link rel="canonical" href="([^"]+)"\s*\/?>/g)];
    const target = file === 'mappa-sedi.html' ? 'dove-trovarmi.html' : file === 'index.html' ? '' : file;
    assert.equal(canonicals.length, 1, `${file} needs exactly one canonical URL`);
    assert.equal(canonicals[0][1], `${origin}/${target}`);
    assert.doesNotMatch(html, /https?:\/\/thaitikmassage\.it(?:[/"'#\s]|$)/, `${file} uses a non-canonical domain`);
  }
  for (const urlPath of urlPaths) {
    const file = urlPath === '/' ? 'index.html' : urlPath.slice(1);
    assert.ok(read(file).includes(`rel="canonical" href="${origin}${urlPath}"`),
      `${file} sitemap URL must be canonical`);
  }
  assert.ok(!urlPaths.includes('/mappa-sedi.html'), 'Redirect aliases must not be in the sitemap');
});

test('all structured-data pages describe the same distinct partner locations using the logo', () => {
  let expectedLocations;
  let pageCount = 0;
  for (const file of htmlFiles) {
    const html = read(file);
    if (!html.includes('application/ld+json')) continue;
    pageCount++;
    const graph = readGraph(html);
    const ids = graph.map(node => node['@id']).filter(Boolean);
    assert.equal(new Set(ids).size, ids.length, `${file} has duplicate entity IDs`);
    const organization = graph.find(node => node['@id'] === `${origin}/#business`);
    assert.equal(organization['@type'], 'Organization');
    assert.deepEqual(organization.location, partners.map(partner => ({ '@id': partner.id })));
    const locations = graph.filter(node => node['@type'] === 'LocalBusiness');
    assert.equal(locations.length, 2, `${file} must describe both partner locations`);
    for (const partner of partners) {
      const location = locations.find(node => node['@id'] === partner.id);
      assert.ok(location, `${file} needs ${partner.id}`);
      assert.equal(location.url, `${origin}/${partner.file}`);
      assert.equal(location.name, `Thai Tik Massage - ${partner.district}`);
      assert.equal(location.image, `${origin}/thai-tik-logo-alternativo.png`);
      assert.equal(location.logo, location.image);
      assert.ok(fs.existsSync(path.join(root, new URL(location.image).pathname)));
      assert.equal(location.address.streetAddress, partner.street);
      assert.equal(location.address.postalCode, partner.cap);
      assert.equal(location.address.addressRegion, 'RM');
      assert.equal(location.address.addressCountry, 'IT');
      assert.match(decodeURIComponent(location.hasMap), new RegExp(partner.street));
      assert.deepEqual(location.areaServed, [
        { '@type': 'Place', name: partner.district },
        { '@type': 'City', name: 'Roma' }
      ]);
      assert.ok(!location.parentOrganization && !location.branchOf,
        'Partner centers must not be represented as owned branches');
    }
    if (expectedLocations) assert.deepEqual(locations, expectedLocations, `${file} has inconsistent location data`);
    expectedLocations = locations;
    assert.doesNotMatch(JSON.stringify(graph), /ThaiMassage|ProfessionalService|00000000/);
    for (const node of graph) {
      if (node.serviceType) assert.equal(node['@type'], 'Service');
      if (node.provider) assert.equal(node.provider['@id'], organization['@id']);
    }
  }
  assert.equal(pageCount, 11, 'Existing structured-data pages and both new partner pages must be covered');
});

test('partner pages are discoverable and have working booking and local links', () => {
  const titles = new Set();
  for (const partner of partners) {
    const html = read(partner.file);
    assert.ok(urlPaths.includes(`/${partner.file}`), `${partner.file} must be in the sitemap`);
    assert.equal([...html.matchAll(/<h1\b/g)].length, 1);
    titles.add(html.match(/<title>([^<]+)<\/title>/)[1]);
    assert.ok(html.includes(partner.street));
    assert.ok(html.includes(partner.cap));
    assert.ok(html.includes(`href="prenota-massaggio.html?studio=${partner.studio}"`));
    assert.ok(html.includes('href="cookie-consent.css"'));
    assert.ok(html.includes('src="cookie-consent.js"'));
    const frames = [...html.matchAll(/<iframe\b[^>]*>/g)];
    assert.equal(frames.length, 1, 'Each partner page needs its own map');
    assert.match(frames[0][0], /data-consent-src="https:\/\/www\.google\.com\/maps\?/);
    assert.match(frames[0][0], /\bhidden\b/);
    assert.doesNotMatch(frames[0][0], /\ssrc=/, 'Maps must not load before consent');
    assert.ok(decodeURIComponent(frames[0][0]).includes(partner.street));
    assert.ok(html.includes('data-open-consent'));
    assert.ok(html.includes('src="studio-hours.js"'));
    const studioName = partner.studio === 'roma' ? 'Studio Prati' : 'APS Studio Montesacro';
    assert.ok(html.includes(`data-studio-hours="${studioName}"`));
    const page = readGraph(html).find(node => node['@type'] === 'WebPage');
    assert.equal(page.url, `${origin}/${partner.file}`);
    assert.equal(page.mainEntity['@id'], partner.id);
    for (const source of ['index.html', 'dove-trovarmi.html', 'prenota.html']) {
      assert.ok(read(source).includes(`href="${partner.file}"`), `${source} must link to ${partner.file}`);
    }
    for (const [, link] of html.matchAll(/(?:href|src)="([^"]+)"/g)) {
      const url = new URL(link.replaceAll('&amp;', '&'), `${origin}/${partner.file}`);
      if (url.origin !== origin) continue;
      const file = url.pathname === '/' ? 'index.html' : url.pathname.slice(1);
      assert.ok(fs.existsSync(path.join(root, file)), `${partner.file} has broken local link ${link}`);
      if (url.hash && file.endsWith('.html')) {
        assert.ok(read(file).includes(`id="${url.hash.slice(1)}"`), `${link} needs an existing anchor`);
      }
    }
  }
  assert.equal(titles.size, 2, 'Partner pages must have distinct titles');
  const aps = read('centro-monte-sacro.html');
  for (const notice of ['socia professionista', 'propria Partita IVA', 'non dall\'APS', 'iscriversi all\'associazione', 'non sono inclusi nel prezzo']) {
    assert.ok(aps.includes(notice), `APS page must preserve notice: ${notice}`);
  }
});

test('footer location links lead to the real two-map overview', () => {
  for (const file of htmlFiles) {
    const html = read(file);
    if (html.includes('Mappa sedi e indirizzi')) {
      assert.ok(html.includes('href="dove-trovarmi.html">Mappa sedi e indirizzi</a>'), file);
    }
  }
  const overview = read('dove-trovarmi.html');
  assert.equal([...overview.matchAll(/<iframe\b/g)].length, 2);
  assert.ok(overview.includes('src="studio-hours.js"'));
});

test('every public header offers both partner pages in the location dropdown', () => {
  let headers = 0;
  for (const file of htmlFiles) {
    const html = read(file);
    const header = html.match(/<header class="site-header">([\s\S]*?)<\/header>/);
    if (!header) continue;
    headers++;
    const dropdowns = [...header[1].matchAll(/<details class="nav-dropdown">([\s\S]*?)<\/details>/g)];
    const locations = dropdowns.filter(([, content]) => content.includes('<summary>Dove trovarmi</summary>'));
    assert.equal(locations.length, 1, `${file} needs one location dropdown`);
    const links = [...locations[0][1].matchAll(/<a href="([^"]+)"([^>]*)>/g)];
    assert.deepEqual(links.map(([, href]) => href),
      ['dove-trovarmi.html', 'centro-prati.html', 'centro-monte-sacro.html'], file);
    for (const [, href, attributes] of links) {
      assert.equal(attributes.includes('aria-current="page"'), href === file,
        `${file} must mark only its own location link as current`);
    }
  }
  assert.equal(headers, 13, 'All shared public headers must offer location navigation');
});
