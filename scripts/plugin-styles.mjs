import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const appRoot = path.resolve(scriptDir, '..');
const binDir = path.join(appRoot, 'node_modules', '.bin');
const executableSuffix = process.platform === 'win32' ? '.cmd' : '';

const targets = {
    app: {
        input: 'resources/scss/app.scss',
        intermediate: 'resources/css/app.css',
        preamble: ['@import "tailwindcss";'],
        tailwind: false,
    },
    accounting: {
        input: 'plugins/webkul/accounting/resources/scss/index.scss',
        intermediate: 'plugins/webkul/accounting/resources/css/index.css',
        output: 'plugins/webkul/accounting/resources/dist/accounting.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../resources/views';",
        ],
    },
    blogs: {
        input: 'plugins/webkul/blogs/resources/scss/index.scss',
        intermediate: 'plugins/webkul/blogs/resources/css/index.css',
        output: 'plugins/webkul/blogs/resources/dist/blogs.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../resources/views';",
        ],
    },
    chatter: {
        input: 'plugins/webkul/chatter/resources/scss/index.scss',
        intermediate: 'plugins/webkul/chatter/resources/css/index.css',
        output: 'plugins/webkul/chatter/resources/dist/chatter.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../resources/views';",
            "@source '../../src';",
        ],
    },
    fields: {
        input: 'plugins/webkul/fields/resources/scss/index.scss',
        intermediate: 'plugins/webkul/fields/resources/css/index.css',
        output: 'plugins/webkul/fields/resources/dist/fields.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../resources/views';",
        ],
    },
    'full-calendar': {
        input: 'plugins/webkul/full-calendar/resources/scss/app.scss',
        intermediate: 'plugins/webkul/full-calendar/resources/css/app.css',
        output: 'plugins/webkul/full-calendar/resources/dist/app.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../resources/views';",
        ],
    },
    'plugin-manager': {
        input: 'plugins/webkul/plugin-manager/resources/scss/index.scss',
        intermediate: 'plugins/webkul/plugin-manager/resources/css/index.css',
        output: 'plugins/webkul/plugin-manager/resources/dist/plugin.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../resources/views';",
            "@source '../../src';",
        ],
    },
    support: {
        input: 'plugins/webkul/support/resources/scss/index.scss',
        intermediate: 'plugins/webkul/support/resources/css/index.css',
        output: 'plugins/webkul/support/resources/dist/support.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../../../app/Filament';",
            "@source '../../resources/views';",
            "@source '../../../../../vendor/guava/filament-icon-picker/resources/views';",
        ],
    },
    'table-views': {
        input: 'plugins/webkul/table-views/resources/scss/index.scss',
        intermediate: 'plugins/webkul/table-views/resources/css/index.css',
        output: 'plugins/webkul/table-views/resources/dist/table-views.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../resources/views';",
        ],
    },
    website: {
        input: 'plugins/webkul/website/resources/scss/index.scss',
        intermediate: 'plugins/webkul/website/resources/css/index.css',
        output: 'plugins/webkul/website/resources/dist/website.css',
        preamble: [
            '@import "tailwindcss";',
            '@variant dark (&:where(.dark, .dark *));',
            "@source '../../resources/views';",
        ],
    },
};

const args = process.argv.slice(2);
const isWatch = args.includes('--watch');
const pluginFlagIndex = args.indexOf('--plugin');
const selectedPluginName = pluginFlagIndex >= 0 ? args[pluginFlagIndex + 1] : null;

if (selectedPluginName && !targets[selectedPluginName]) {
    console.error(`Unknown plugin style target: ${selectedPluginName}`);
    process.exit(1);
}

const selectedTargets = selectedPluginName
    ? [[selectedPluginName, targets[selectedPluginName]]]
    : Object.entries(targets);

const tailwindBin = path.join(binDir, `tailwindcss${executableSuffix}`);
const importPattern = /^\s*@(?:use|import)\s+["']([^"']+)["'];\s*$/gm;

function toAbsolute(relativePath) {
    return path.join(appRoot, relativePath);
}

function ensureDirectories(target) {
    mkdirSync(path.dirname(toAbsolute(target.intermediate)), { recursive: true });

    if (target.output) {
        mkdirSync(path.dirname(toAbsolute(target.output)), { recursive: true });
    }
}

function resolveLocalImport(fromDirectory, request) {
    if (!request.startsWith('.') && !request.startsWith('..')) {
        return null;
    }

    const rawTarget = path.resolve(fromDirectory, request);
    const baseName = path.basename(rawTarget);
    const directory = path.dirname(rawTarget);

    const candidates = [
        rawTarget,
        `${rawTarget}.scss`,
        `${rawTarget}.css`,
        path.join(rawTarget, 'index.scss'),
        path.join(rawTarget, 'index.css'),
        path.join(directory, `_${baseName}.scss`),
        path.join(directory, `_${baseName}.css`),
    ];

    for (const candidate of candidates) {
        try {
            if (statSync(candidate).isFile()) {
                return candidate;
            }
        } catch {
            continue;
        }
    }

    throw new Error(`Unable to resolve local style import "${request}" from ${fromDirectory}`);
}

function compileSourceFile(filePath, visited = new Set()) {
    const absolutePath = path.resolve(filePath);

    if (visited.has(absolutePath)) {
        return '';
    }

    visited.add(absolutePath);

    const source = readFileSync(absolutePath, 'utf8');
    const directory = path.dirname(absolutePath);

    return source.replace(importPattern, (statement, request) => {
        const resolvedImport = resolveLocalImport(directory, request);

        if (!resolvedImport) {
            return statement;
        }

        return compileSourceFile(resolvedImport, visited);
    });
}

function collectSourceFiles(filePath, files = new Set()) {
    const absolutePath = path.resolve(filePath);

    if (files.has(absolutePath)) {
        return files;
    }

    files.add(absolutePath);

    const source = readFileSync(absolutePath, 'utf8');
    const directory = path.dirname(absolutePath);

    for (const match of source.matchAll(importPattern)) {
        const resolvedImport = resolveLocalImport(directory, match[1]);

        if (resolvedImport) {
            collectSourceFiles(resolvedImport, files);
        }
    }

    return files;
}

function writeIntermediate(target) {
    const stylesheet = compileSourceFile(toAbsolute(target.input)).trim();
    const preamble = target.preamble?.length ? `${target.preamble.join('\n')}\n\n` : '';
    const output = stylesheet.length > 0 ? `${preamble}${stylesheet}\n` : preamble;

    writeFileSync(toAbsolute(target.intermediate), output, 'utf8');
}

function run(command, commandArgs) {
    const result = spawnSync(command, commandArgs, {
        cwd: appRoot,
        stdio: 'inherit',
    });

    if (result.status !== 0) {
        process.exit(result.status ?? 1);
    }
}

function buildTarget(target, minify = false) {
    ensureDirectories(target);
    writeIntermediate(target);

    if (target.tailwind === false) {
        return;
    }

    const tailwindArgs = [
        '-i',
        toAbsolute(target.intermediate),
        '-o',
        toAbsolute(target.output),
    ];

    if (minify) {
        tailwindArgs.push('--minify');
    }

    run(tailwindBin, tailwindArgs);
}

function createSignature(target) {
    return Array.from(collectSourceFiles(toAbsolute(target.input)))
        .sort()
        .map((file) => `${file}:${statSync(file).mtimeMs}`)
        .join('|');
}

if (isWatch) {
    const signatures = new Map();

    for (const [name, target] of selectedTargets) {
        buildTarget(target, false);
        signatures.set(name, createSignature(target));
    }

    setInterval(() => {
        for (const [name, target] of selectedTargets) {
            const nextSignature = createSignature(target);

            if (signatures.get(name) === nextSignature) {
                continue;
            }

            signatures.set(name, nextSignature);
            buildTarget(target, false);
            console.log(`[styles] rebuilt ${name}`);
        }
    }, 1000);
} else {
    for (const [, target] of selectedTargets) {
        buildTarget(target, true);
    }
}
