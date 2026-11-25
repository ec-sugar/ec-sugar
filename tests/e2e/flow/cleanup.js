import { runFlow } from './runner.js';

// 残した試しの店を消す（DB ごと）と、その店のスタッフを止める: npm run e2e:flow:cleanup -- flow1234abcd5678
const id = process.argv[2] ?? '';
console.log(JSON.stringify(runFlow('cleanup', id, `${id}@example.com`)));
