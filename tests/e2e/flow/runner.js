import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { phpCommand } from './config.js';

// flow.php を確かめる先のアプリの中で動かす（ファイルは標準入力で渡す。php -- 命令 引数…）。最後の行の JSON を返す
const script = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'flow.php'));

export function runFlow(...args) {
    const [command, ...prefix] = phpCommand.split(/\s+/).filter(Boolean);
    let output;

    try {
        output = execFileSync(command, [...prefix, '--', ...args.map(String)], { input: script, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 180_000 });
    } catch (error) {
        throw new Error(`flow.php ${args.join(' ')} が失敗しました:\n${error.stderr || error.message}`);
    }

    try {
        return JSON.parse(output.trim().split('\n').pop() ?? '');
    } catch {
        throw new Error(`flow.php ${args.join(' ')} の答えが読めません:\n${output}`);
    }
}
