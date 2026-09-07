/**
 * Genera el set de íconos PWA/favicon a partir de los SVG maestros en
 * resources/svg/. Script de un solo uso / re-ejecutable si el isotipo
 * cambia — no forma parte del pipeline de build de Vite.
 *
 * Uso: node scripts/generate-icons.mjs
 */
import sharp from 'sharp';
import toIco from 'to-ico';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const OUT_DIR = 'public/icons';
mkdirSync(OUT_DIR, { recursive: true });

const masterSvg = readFileSync('resources/svg/isotipo-master.svg');
const maskableSvg = readFileSync('resources/svg/isotipo-master-maskable.svg');

async function renderPng(svgBuffer, size, outPath) {
    const buffer = await sharp(svgBuffer, { density: 384 })
        .resize(size, size)
        .png()
        .toBuffer();
    writeFileSync(outPath, buffer);
    console.log(`  ${outPath} (${size}x${size})`);
}

async function main() {
    console.log('Generando íconos...');

    writeFileSync(`${OUT_DIR}/favicon.svg`, masterSvg);
    console.log(`  ${OUT_DIR}/favicon.svg`);

    await renderPng(masterSvg, 180, `${OUT_DIR}/apple-touch-icon.png`);
    await renderPng(masterSvg, 192, `${OUT_DIR}/icon-192.png`);
    await renderPng(masterSvg, 512, `${OUT_DIR}/icon-512.png`);
    await renderPng(maskableSvg, 192, `${OUT_DIR}/icon-192-maskable.png`);
    await renderPng(maskableSvg, 512, `${OUT_DIR}/icon-512-maskable.png`);

    const favicon16 = await sharp(masterSvg, { density: 384 }).resize(16, 16).png().toBuffer();
    const favicon32 = await sharp(masterSvg, { density: 384 }).resize(32, 32).png().toBuffer();
    const favicon48 = await sharp(masterSvg, { density: 384 }).resize(48, 48).png().toBuffer();
    const icoBuffer = await toIco([favicon16, favicon32, favicon48]);
    writeFileSync(`${OUT_DIR}/favicon.ico`, icoBuffer);
    console.log(`  ${OUT_DIR}/favicon.ico (16/32/48)`);

    console.log('Listo.');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
