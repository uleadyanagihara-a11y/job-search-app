// npm audit の結果を、許容済みの advisory（audit-accepted.json）と照合する。
// 許容の理由と再評価の条件は docs/accepted-vulnerabilities.md に記録している。
//
// 次のいずれかで失敗する:
// - 許容リストに無い advisory が出た（新しい脆弱性。対応するか、記録してから許容する）
// - 許容済みの advisory に、Tailwind 4 への major 更新以外の修正手段が出た（修正版の公開。再評価する）
// - 許容リストの advisory が audit に出なくなった（解消済み。リストと記録を整理する）
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const accepted = JSON.parse(readFileSync(new URL('../audit-accepted.json', import.meta.url), 'utf8'));
const acceptedIds = new Set(Object.keys(accepted.advisories));

const audit = spawnSync('npm', ['audit', '--json'], { encoding: 'utf8' });
let report;
try {
    report = JSON.parse(audit.stdout);
} catch {
    console.error('npm audit の出力を JSON として読めない。');
    console.error(audit.stderr);
    process.exit(1);
}
if (report.error) {
    console.error('npm audit が失敗した:', report.error.summary ?? report.error);
    process.exit(1);
}

const vulnerabilities = Object.values(report.vulnerabilities ?? {});
const advisoryId = (via) => via.url.replace(/^.*\/advisories\//, '');

// 許容した修正手段（major 更新で、許容の前提を変える更新）かどうか
const isAcceptedFix = (fix) =>
    typeof fix === 'object' && fix.isSemVerMajor && fix.name === accepted.acceptedFixVia;

const errors = [];
const found = new Set();

for (const vulnerability of vulnerabilities) {
    for (const via of vulnerability.via) {
        if (typeof via === 'string') {
            continue;
        }
        const id = advisoryId(via);
        found.add(id);

        if (!acceptedIds.has(id)) {
            errors.push(`未許容の advisory: ${vulnerability.name} ${id}（${via.severity}）${via.title}`);
        }
    }
}

// 許容済み advisory の影響を受ける package（via が文字列の連鎖も含む）に、許容外の修正手段が出たか
for (const vulnerability of vulnerabilities) {
    if (vulnerability.fixAvailable !== false && !isAcceptedFix(vulnerability.fixAvailable)) {
        errors.push(
            `修正手段が公開された: ${vulnerability.name}（fixAvailable: ${JSON.stringify(vulnerability.fixAvailable)}）。docs/accepted-vulnerabilities.md に沿って再評価する`,
        );
    }
}

for (const id of acceptedIds) {
    if (!found.has(id)) {
        errors.push(`許容済みの advisory が出なくなった: ${id}。audit-accepted.json と docs/accepted-vulnerabilities.md を整理する`);
    }
}

if (errors.length > 0) {
    for (const error of errors) {
        console.error(error);
    }
    process.exit(1);
}

console.log(`npm audit: 許容済みの advisory のみ（${[...found].join(', ') || 'なし'}）`);
