/* Author: Gündoğdu */
import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createRequire} from 'node:module';
const root=path.dirname(fileURLToPath(import.meta.url));
const require=createRequire(process.env.TAILWIND_DEPENDENCY_ROOT?path.join(process.env.TAILWIND_DEPENDENCY_ROOT,'package.json'):import.meta.url);
const postcss=require('postcss'),tailwind=require('@tailwindcss/postcss');
let source=await fs.readFile(path.join(root,'src/tailwind.css'),'utf8');
if(process.env.TAILWIND_DEPENDENCY_ROOT)source=source.replace('"tailwindcss"',JSON.stringify(path.join(process.env.TAILWIND_DEPENDENCY_ROOT,'node_modules/tailwindcss/index.css')));
const result=await postcss([tailwind({base:root})]).process(source,{from:path.join(root,'src/tailwind.css'),to:path.join(root,'assets/tailwind.css')});
await fs.writeFile(path.join(root,'assets/tailwind.css'),result.css);
console.log('Tailwind CSS derlendi.');
