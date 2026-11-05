// npm audit の結果を、許容済みの advisory（audit-accepted.json）と照合する。
// 許容の理由と再評価の条件は docs/accepted-vulnerabilities.md に記録している。
//
// 次のいずれかで失敗する:
// - 許容リストに無い advisory が出た（新しい脆弱性。対応するか、記録してから許容する）
// - 許容済みの advisory に、今より古い版へのダウングレード以外の修正手段が出た（修正版の公開。再評価する）
// - 許容リストの advisory が audit に出なくなった（解消済み。リストと記録を整理する）
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const accepted = JSON.parse(readFileSync(new URL('../audit-accepted.json', import.meta.url), 'utf8'));
const acceptedIds = new Set(Object.keys(accepted.advisories));

// CI は npm ci しないので、直接依存の版は lockfile から読む
const lockPackages = JSON.parse(readFileSync(new URL('../package-lock.json', import.meta.url), 'utf8')).packages;
const installedVersion = (name) => lockPackages[`node_modules/${name}`]?.version;

// x.y.z の数値部分だけで比べる（prerelease は無視）。a < b なら負
const compareVersions = (a, b) => {
    const parse = (v) => v.split(/[-+]/)[0].split('.').map(Number);
    const [pa, pb] = [parse(a), parse(b)];
    for (let i = 0; i < 3; i++) {
        if ((pa[i] ?? 0) !== (pb[i] ?? 0)) {
            return (pa[i] ?? 0) - (pb[i] ?? 0);
        }
    }
    return 0;
};

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

// 修正手段として扱わないもの: npm audit が示す「修正」が、直接依存を今より古い版に戻すダウングレードの場合。
// 依存元が修正版を受け付けないとき、npm は脆弱な依存を含まない大昔の版（例: eslint 4.0.0）を提案してくる。
// 同じ版や新しい版への major 更新は、修正手段の公開として再評価の対象にする。
const isDowngrade = (fix) => {
    if (typeof fix !== 'object' || !fix.isSemVerMajor) {
        return false;
    }
    const current = installedVersion(fix.name);
    return current !== undefined && compareVersions(fix.version, current) < 0;
};

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
    if (vulnerability.fixAvailable !== false && !isDowngrade(vulnerability.fixAvailable)) {
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
