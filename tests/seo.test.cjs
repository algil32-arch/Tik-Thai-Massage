const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const sitemapPath = path.join(root, 'sitemap.xml');
const sitemap = fs.readFileSync(sitemapPath, 'utf8');
const urlPaths = [...sitemap.matchAll(/<loc>https:\/\/www\.thaitikmassage\.it(\/[^<]*)?<\/loc>/g)]
  .map(([, urlPath]) => urlPath || '/');

test('all HTML pages define a title and meta description', () => {
  const htmlFiles = fs.readdirSync(root).filter((file) => file.endsWith('.html'));
  for (const file of htmlFiles) {
    const html = fs.readFileSync(path.join(root, file), 'utf8');
    assert.match(html, /<title>[^<]+<\/title>/i, `${file} needs a non-empty title`);
    assert.match(html, /<meta\s+name=["']description["']\s+content=["'][^"']+["']/i, `${file} needs a meta description`);
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
