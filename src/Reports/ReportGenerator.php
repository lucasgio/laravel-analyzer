<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Reports;

class ReportGenerator
{
    private array  $results;
    private array  $options;
    private string $projectPath;

    private const SECTION_LABELS = [
        'coupling'    => '🔗 Coupling & Cohesion',
        'testing'     => '🧪 Test Coverage',
        'debt'        => '💸 Technical Debt',
        'complexity'  => '🧮 Complexity',
        'security'    => '🔒 Laravel Security',
        'owasp'       => '🛡️  OWASP Top 10',
        'refactoring' => '♻️  Refactoring Opportunities',
    ];

    public function __construct(array $results, array $options, string $projectPath)
    {
        $this->results     = $results;
        $this->options     = $options;
        $this->projectPath = $projectPath;
    }

    // ─────────────────────────────────────────
    // CONSOLE OUTPUT
    // ─────────────────────────────────────────

    public function toConsole(bool $noColor = false): void
    {
        $color = fn(string $text, string $c) => $noColor ? $text : $this->color($text, $c);

        echo $color("\n📋 DETAILED ANALYSIS REPORT\n", 'cyan');
        echo str_repeat('═', 60) . PHP_EOL;

        foreach ($this->results as $key => $result) {
            if (isset($result['error'])) {
                echo $color("\n⚠ " . self::SECTION_LABELS[$key] . ": ERROR - " . $result['error'], 'red') . PHP_EOL;
                continue;
            }

            $label    = self::SECTION_LABELS[$key] ?? $key;
            $score    = $result['score'] ?? 0;
            $risk     = $result['risk'] ?? 'N/A';
            $scoreColor = $score >= 80 ? 'green' : ($score >= 60 ? 'yellow' : 'red');

            echo PHP_EOL;
            echo $color("┌─ {$label}", 'cyan') . PHP_EOL;
            echo $color("│  Score: ", 'white') . $color(sprintf("%.1f/100", $score), $scoreColor);
            echo "  Risk: " . $color($risk, $scoreColor) . PHP_EOL;
            echo $color("│  ", 'cyan') . $this->progressBar($score, $noColor) . PHP_EOL;

            if (!empty($result['summary'])) {
                echo $color("│  Summary: ", 'white') . wordwrap($result['summary'], 55, "\n│           ", true) . PHP_EOL;
            }

            // Show metrics
            if (!empty($result['metrics'])) {
                echo $color("│\n│  Key metrics:\n", 'white');
                $this->printMetrics($result['metrics'], $noColor);
            }

            // Show top issues
            $criticalIssues = array_filter($result['issues'] ?? [], fn($i) => in_array($i['severity'], ['CRITICAL', 'HIGH']));
            if (!empty($criticalIssues)) {
                echo $color("│\n│  ⚠ Critical/High Issues (top 3):\n", 'yellow');
                foreach (array_slice(array_values($criticalIssues), 0, 3) as $issue) {
                    $sev = $issue['severity'] === 'CRITICAL' ? '🔴' : '🟠';
                    $msg = wordwrap($issue['message'], 52, "\n│     ", true);
                    echo "│  {$sev} [{$issue['file']}]\n│     {$msg}\n";
                }
            }

            // Show OWASP breakdown if available
            if ($key === 'owasp' && !empty($result['metrics']['owasp_breakdown'])) {
                echo $color("│\n│  OWASP Top 10 Breakdown:\n", 'white');
                foreach ($result['metrics']['owasp_breakdown'] as $code => $owasp) {
                    $sc    = $owasp['score'];
                    $col   = $sc >= 80 ? 'green' : ($sc >= 60 ? 'yellow' : 'red');
                    $bar   = $noColor ? "[" . str_pad('', (int)($sc/5), '█') . "]" : '';
                    echo "│   {$code}: " . $color(sprintf("%-45s %3.0f/100", $owasp['name'], $sc), $col) . "\n";
                }
            }

            // Show recommendations (top 2)
            if (!empty($result['recommendations'])) {
                echo $color("│\n│  💡 Recommendations:\n", 'cyan');
                foreach (array_slice($result['recommendations'], 0, 2) as $rec) {
                    $msg = wordwrap("• {$rec}", 55, "\n│    ", true);
                    echo "│  {$msg}\n";
                }
            }

            echo $color("└" . str_repeat('─', 59), 'cyan') . PHP_EOL;
        }
    }

    private function printMetrics(array $metrics, bool $noColor): void
    {
        $skip = ['issues', 'recommendations', 'owasp_breakdown', 'worst_offenders', 'most_complex_methods',
                 'top_vulnerabilities', 'worst_antipatterns', 'untested_areas'];

        foreach ($metrics as $key => $value) {
            if (in_array($key, $skip)) continue;
            if (is_array($value)) {
                if (isset($value[0]) && is_string($value[0])) {
                    // Simple string array
                    $label = str_pad(ucwords(str_replace('_', ' ', $key)), 30);
                    echo "│    {$label}: " . implode(', ', array_slice($value, 0, 3)) . "\n";
                }
                continue;
            }
            $label = str_pad(ucwords(str_replace('_', ' ', $key)), 30);
            echo "│    {$label}: {$value}\n";
        }
    }

    // ─────────────────────────────────────────
    // JSON OUTPUT
    // ─────────────────────────────────────────

    public function toJson(): string
    {
        $report = [
            'generated_at'   => date('Y-m-d H:i:s'),
            'project'        => basename($this->projectPath),
            'project_path'   => $this->projectPath,
            'global_score'   => $this->calculateGlobalScore(),
            'grade'          => $this->getGrade($this->calculateGlobalScore()),
            'analyses'       => $this->results,
        ];

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ─────────────────────────────────────────
    // MARKDOWN OUTPUT
    // ─────────────────────────────────────────

    public function toMarkdown(): string
    {
        $globalScore = $this->calculateGlobalScore();
        $grade       = $this->getGrade($globalScore);
        $date        = date('Y-m-d H:i:s');
        $project     = basename($this->projectPath);

        $md = "# 🔍 Laravel Best Practices Analysis Report\n\n";
        $md .= "> **Project:** `{$project}` | **Date:** {$date} | **Global Score:** " . sprintf("%.1f", $globalScore) . "/100 [{$grade}]\n\n";
        $md .= "---\n\n";

        // Summary table
        $md .= "## 📊 Executive Summary\n\n";
        $md .= "| Module | Score | Risk | Critical Issues |\n";
        $md .= "|--------|-------|------|----------------|\n";

        foreach ($this->results as $key => $result) {
            if (isset($result['error'])) continue;
            $label    = self::SECTION_LABELS[$key] ?? $key;
            $score    = sprintf("%.1f", $result['score'] ?? 0);
            $risk     = $result['risk'] ?? 'N/A';
            $critical = count(array_filter($result['issues'] ?? [], fn($i) => $i['severity'] === 'CRITICAL'));
            $md .= "| {$label} | {$score}/100 | {$risk} | {$critical} |\n";
        }

        $md .= "\n---\n\n";

        foreach ($this->results as $key => $result) {
            if (isset($result['error'])) continue;
            $label = self::SECTION_LABELS[$key] ?? $key;
            $score = sprintf("%.1f", $result['score'] ?? 0);

            $md .= "## {$label}\n\n";
            $md .= "**Score:** {$score}/100 | **Risk:** {$result['risk']}\n\n";

            if (!empty($result['summary'])) {
                $md .= "> {$result['summary']}\n\n";
            }

            // Metrics
            if (!empty($result['metrics'])) {
                $md .= "### Metrics\n\n";
                foreach ($result['metrics'] as $k => $v) {
                    if (is_array($v)) continue;
                    $label_m = ucwords(str_replace('_', ' ', $k));
                    $md .= "- **{$label_m}:** {$v}\n";
                }
                $md .= "\n";
            }

            // Issues
            $critIssues = array_filter($result['issues'] ?? [], fn($i) => in_array($i['severity'], ['CRITICAL', 'HIGH']));
            if (!empty($critIssues)) {
                $md .= "### ⚠ Critical/High Issues\n\n";
                foreach (array_slice(array_values($critIssues), 0, 10) as $issue) {
                    $sev = $issue['severity'] === 'CRITICAL' ? '🔴' : '🟠';
                    $md .= "- {$sev} **[{$issue['file']}]** {$issue['message']}\n";
                }
                $md .= "\n";
            }

            // OWASP breakdown
            if ($key === 'owasp' && !empty($result['metrics']['owasp_breakdown'])) {
                $md .= "### OWASP Top 10 Breakdown\n\n";
                $md .= "| Category | Name | Score | Risk |\n";
                $md .= "|----------|------|-------|------|\n";
                foreach ($result['metrics']['owasp_breakdown'] as $code => $owasp) {
                    $md .= "| {$code} | {$owasp['name']} | {$owasp['score']}/100 | {$owasp['risk']} |\n";
                }
                $md .= "\n";
            }

            // Recommendations
            if (!empty($result['recommendations'])) {
                $md .= "### 💡 Recommendations\n\n";
                foreach ($result['recommendations'] as $rec) {
                    $md .= "1. {$rec}\n";
                }
                $md .= "\n";
            }

            $md .= "---\n\n";
        }

        $md .= $this->buildPerFileMarkdown();

        $md .= "_Generated by Laravel Best Practices Analyzer v1.0.0_\n";

        return $md;
    }

    private function buildPerFileMarkdown(): string
    {
        // Aggregate file_issues from all modules
        $allFileIssues = $this->aggregateFileIssues();

        if (empty($allFileIssues)) {
            return '';
        }

        // Sort files by total issue count descending
        uasort($allFileIssues, fn($a, $b) => count($b) <=> count($a));

        $md  = "---\n\n";
        $md .= "## 📁 Per-File Report\n\n";
        $md .= "> Files listed in order of most issues. Only files with at least one issue are shown.\n\n";

        foreach ($allFileIssues as $file => $issues) {
            $total    = count($issues);
            $critical = count(array_filter($issues, fn($i) => $i['severity'] === 'CRITICAL'));
            $high     = count(array_filter($issues, fn($i) => $i['severity'] === 'HIGH'));
            $medium   = count(array_filter($issues, fn($i) => $i['severity'] === 'MEDIUM'));

            $md .= "### `{$file}`\n\n";
            $md .= "**Issues:** {$total}";
            if ($critical) $md .= " · 🔴 Critical: {$critical}";
            if ($high)     $md .= " · 🟠 High: {$high}";
            if ($medium)   $md .= " · 🟡 Medium: {$medium}";
            $md .= "\n\n";

            $md .= "| Line | Severity | Module | Message |\n";
            $md .= "|------|----------|--------|---------|\n";

            foreach ($issues as $issue) {
                $line   = $issue['line'] > 0 ? $issue['line'] : '—';
                $sev    = $issue['severity'];
                $module = $issue['module'] ?? '—';
                $msg    = str_replace('|', '\\|', $issue['message']);
                $md .= "| {$line} | {$sev} | {$module} | {$msg} |\n";
            }

            $md .= "\n";
        }

        return $md;
    }

    // ─────────────────────────────────────────
    // HTML OUTPUT
    // ─────────────────────────────────────────

    public function toHtml(): string
    {
        $globalScore = $this->calculateGlobalScore();
        $grade       = $this->getGrade($globalScore);
        $date        = date('Y-m-d H:i:s');
        $project     = basename($this->projectPath);
        $scoreColor  = $globalScore >= 80 ? '#22c55e' : ($globalScore >= 60 ? '#f59e0b' : '#ef4444');

        $sectionsHtml = '';
        foreach ($this->results as $key => $result) {
            if (isset($result['error'])) continue;
            $sectionsHtml .= $this->renderHtmlSection($key, $result);
        }

        $perFileHtml = $this->buildPerFileHtml();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laravel Analyzer Report — {$project}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0f172a; color: #e2e8f0; line-height: 1.6; }
  .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
  .header { background: linear-gradient(135deg, #1e3a5f 0%, #0f2744 100%); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; border: 1px solid #334155; }
  .header h1 { font-size: 1.8rem; font-weight: 700; color: #38bdf8; margin-bottom: 0.5rem; }
  .header .meta { color: #94a3b8; font-size: 0.9rem; }
  .global-score { font-size: 4rem; font-weight: 900; color: {$scoreColor}; }
  .grade-badge { display: inline-block; background: {$scoreColor}20; color: {$scoreColor}; border: 1px solid {$scoreColor}; border-radius: 8px; padding: 0.2rem 0.8rem; font-size: 1.2rem; font-weight: 700; margin-left: 1rem; }
  /* Tabs */
  .tabs { display: flex; gap: 0; margin-bottom: 0; border-bottom: 2px solid #334155; }
  .tab-btn { padding: 0.7rem 1.8rem; background: transparent; border: none; color: #94a3b8; font-size: 0.95rem; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
  .tab-btn:hover { color: #e2e8f0; }
  .tab-btn.active { color: #38bdf8; border-bottom-color: #38bdf8; }
  .tab-panel { display: none; padding-top: 1.5rem; }
  .tab-panel.active { display: block; }
  /* Cards */
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
  .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 1.5rem; transition: border-color 0.2s; }
  .card:hover { border-color: #38bdf8; }
  .card-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; }
  .score-row { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
  .score-num { font-size: 2rem; font-weight: 700; }
  .risk-badge { padding: 0.2rem 0.7rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
  .risk-LOW { background: #166534; color: #bbf7d0; }
  .risk-MEDIUM { background: #713f12; color: #fde68a; }
  .risk-HIGH { background: #7c2d12; color: #fed7aa; }
  .risk-CRITICAL { background: #450a0a; color: #fecaca; }
  .sev-CRITICAL { color: #ef4444; font-weight: 700; }
  .sev-HIGH { color: #f97316; font-weight: 700; }
  .sev-MEDIUM { color: #f59e0b; font-weight: 600; }
  .sev-LOW { color: #94a3b8; }
  .progress-bar { width: 100%; height: 8px; background: #334155; border-radius: 4px; overflow: hidden; margin: 0.5rem 0; }
  .progress-fill { height: 100%; border-radius: 4px; }
  .metric-row { display: flex; justify-content: space-between; padding: 0.2rem 0; border-bottom: 1px solid #0f172a; font-size: 0.82rem; color: #94a3b8; }
  .issue { padding: 0.5rem 0.75rem; border-radius: 6px; margin: 0.25rem 0; font-size: 0.82rem; }
  .issue-CRITICAL { background: #450a0a; border-left: 3px solid #ef4444; }
  .issue-HIGH { background: #431407; border-left: 3px solid #f97316; }
  .issue-MEDIUM { background: #422006; border-left: 3px solid #f59e0b; }
  .issue-file { color: #94a3b8; font-family: monospace; font-size: 0.78rem; }
  .rec { padding: 0.4rem 0.75rem; background: #0c2840; border-left: 3px solid #38bdf8; border-radius: 0 6px 6px 0; margin: 0.3rem 0; font-size: 0.82rem; color: #bae6fd; }
  .owasp-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
  .owasp-table th { background: #0f172a; padding: 0.5rem; text-align: left; color: #94a3b8; }
  .owasp-table td { padding: 0.4rem 0.5rem; border-bottom: 1px solid #1e293b; }
  .section-title { font-size: 1.1rem; font-weight: 700; color: #38bdf8; margin: 1.5rem 0 0.8rem; padding-bottom: 0.4rem; border-bottom: 1px solid #334155; }
  /* Per-file report */
  .file-block { background: #1e293b; border: 1px solid #334155; border-radius: 10px; margin-bottom: 1rem; overflow: hidden; }
  .file-header { display: flex; align-items: center; justify-content: space-between; padding: 0.7rem 1rem; background: #162032; cursor: pointer; user-select: none; }
  .file-path { font-family: monospace; font-size: 0.85rem; color: #7dd3fc; }
  .file-badges { display: flex; gap: 0.4rem; flex-shrink: 0; }
  .file-body { display: none; padding: 0.5rem 1rem 1rem; }
  .file-body.open { display: block; }
  .file-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
  .file-table th { text-align: left; padding: 0.4rem 0.5rem; color: #64748b; border-bottom: 1px solid #334155; }
  .file-table td { padding: 0.35rem 0.5rem; border-bottom: 1px solid #0f172a; vertical-align: top; }
  .file-filter { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
  .filter-btn { padding: 0.3rem 0.8rem; border-radius: 20px; border: 1px solid #334155; background: transparent; color: #94a3b8; font-size: 0.78rem; cursor: pointer; transition: all 0.2s; }
  .filter-btn.active, .filter-btn:hover { background: #38bdf820; color: #38bdf8; border-color: #38bdf8; }
  input.search-box { width: 100%; padding: 0.5rem 1rem; background: #1e293b; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; font-size: 0.9rem; margin-bottom: 1rem; outline: none; }
  input.search-box:focus { border-color: #38bdf8; }
  footer { text-align: center; color: #475569; font-size: 0.8rem; margin-top: 3rem; padding-top: 1rem; border-top: 1px solid #1e293b; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>🔍 Laravel Best Practices Analyzer</h1>
    <div class="meta">Project: <strong>{$project}</strong> · Generated: {$date}</div>
    <div style="margin-top:1rem;">
      <span class="global-score">{$globalScore}</span><span style="font-size:2rem;color:#64748b">/100</span>
      <span class="grade-badge">{$grade}</span>
    </div>
  </div>

  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('summary', this)">📊 Summary</button>
    <button class="tab-btn" onclick="switchTab('perfile', this)">📁 Per-File Report</button>
  </div>

  <div id="tab-summary" class="tab-panel active">
    <div class="section-title">Analysis by Module</div>
    <div class="grid">
      {$sectionsHtml}
    </div>
  </div>

  <div id="tab-perfile" class="tab-panel">
    {$perFileHtml}
  </div>

  <footer>Generated by Laravel Best Practices Analyzer v1.0.0 · {$date}</footer>
</div>
<script>
function switchTab(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}
function toggleFile(id) {
  var el = document.getElementById(id);
  el.classList.toggle('open');
}
function filterFiles(sev) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
  document.querySelectorAll('.file-block').forEach(function(block) {
    if (sev === 'ALL') { block.style.display = ''; return; }
    block.style.display = block.dataset.severities && block.dataset.severities.includes(sev) ? '' : 'none';
  });
}
function searchFiles() {
  var q = document.getElementById('file-search').value.toLowerCase();
  document.querySelectorAll('.file-block').forEach(function(block) {
    block.style.display = block.dataset.file && block.dataset.file.includes(q) ? '' : 'none';
  });
}
</script>
</body>
</html>
HTML;
    }

    private function buildPerFileHtml(): string
    {
        $allFileIssues = $this->aggregateFileIssues();

        if (empty($allFileIssues)) {
            return '<p style="color:#64748b;padding:2rem 0">No per-file issues found.</p>';
        }

        uasort($allFileIssues, fn($a, $b) => count($b) <=> count($a));

        $totalFiles  = count($allFileIssues);
        $totalIssues = array_sum(array_map('count', $allFileIssues));

        $html  = "<div style='color:#94a3b8;margin-bottom:1rem;font-size:0.9rem'>";
        $html .= "{$totalFiles} files with issues · {$totalIssues} total findings</div>";

        $html .= "<input class='search-box' id='file-search' placeholder='🔍 Filter by file path...' oninput='searchFiles()'>";
        $html .= "<div class='file-filter'>";
        $html .= "<button class='filter-btn active' onclick='filterFiles(\"ALL\")'>All</button>";
        $html .= "<button class='filter-btn' onclick='filterFiles(\"CRITICAL\")'>🔴 Critical</button>";
        $html .= "<button class='filter-btn' onclick='filterFiles(\"HIGH\")'>🟠 High</button>";
        $html .= "<button class='filter-btn' onclick='filterFiles(\"MEDIUM\")'>🟡 Medium</button>";
        $html .= "<button class='filter-btn' onclick='filterFiles(\"LOW\")'>⚪ Low</button>";
        $html .= "</div>";

        $idx = 0;
        foreach ($allFileIssues as $file => $issues) {
            $idx++;
            $id         = 'file-' . $idx;
            $total      = count($issues);
            $critical   = count(array_filter($issues, fn($i) => $i['severity'] === 'CRITICAL'));
            $high       = count(array_filter($issues, fn($i) => $i['severity'] === 'HIGH'));
            $medium     = count(array_filter($issues, fn($i) => $i['severity'] === 'MEDIUM'));
            $low        = count(array_filter($issues, fn($i) => $i['severity'] === 'LOW'));
            $sevList    = implode(',', array_unique(array_column($issues, 'severity')));
            $fileEsc    = htmlspecialchars($file);

            $badges = '';
            if ($critical) $badges .= "<span class='risk-badge risk-CRITICAL'>{$critical} critical</span>";
            if ($high)     $badges .= "<span class='risk-badge risk-HIGH'>{$high} high</span>";
            if ($medium)   $badges .= "<span class='risk-badge risk-MEDIUM'>{$medium} med</span>";
            if ($low)      $badges .= "<span style='color:#64748b;font-size:0.75rem'>{$low} low</span>";

            $rows = '';
            foreach ($issues as $issue) {
                $line   = $issue['line'] > 0 ? $issue['line'] : '—';
                $sev    = $issue['severity'];
                $module = htmlspecialchars($issue['module'] ?? '—');
                $msg    = htmlspecialchars($issue['message']);
                $rows  .= "<tr><td style='width:50px;color:#64748b'>{$line}</td>";
                $rows  .= "<td style='width:90px'><span class='sev-{$sev}'>{$sev}</span></td>";
                $rows  .= "<td style='width:110px;color:#7dd3fc;font-size:0.78rem'>{$module}</td>";
                $rows  .= "<td>{$msg}</td></tr>";
            }

            $html .= "<div class='file-block' data-file='{$fileEsc}' data-severities='{$sevList}'>";
            $html .= "<div class='file-header' onclick='toggleFile(\"{$id}\")'>";
            $html .= "<span class='file-path'>{$fileEsc}</span>";
            $html .= "<div class='file-badges'>{$badges}<span style='color:#64748b;font-size:0.8rem;margin-left:0.5rem'>{$total} issues ▾</span></div>";
            $html .= "</div>";
            $html .= "<div class='file-body' id='{$id}'>";
            $html .= "<table class='file-table'><thead><tr><th>Line</th><th>Severity</th><th>Module</th><th>Message</th></tr></thead><tbody>{$rows}</tbody></table>";
            $html .= "</div></div>";
        }

        return $html;
    }

    private function renderHtmlSection(string $key, array $result): string
    {
        $label   = self::SECTION_LABELS[$key] ?? $key;
        $score   = round($result['score'] ?? 0, 1);
        $risk    = $result['risk'] ?? 'N/A';
        $color   = $score >= 80 ? '#22c55e' : ($score >= 60 ? '#f59e0b' : '#ef4444');
        $summary = htmlspecialchars($result['summary'] ?? '');

        $metricsHtml = '';
        $skip = ['owasp_breakdown','worst_offenders','most_complex_methods','top_vulnerabilities','worst_antipatterns','untested_areas'];
        foreach ($result['metrics'] ?? [] as $k => $v) {
            if (in_array($k, $skip) || is_array($v)) continue;
            $label_m = ucwords(str_replace('_', ' ', $k));
            $metricsHtml .= "<div class='metric-row'><span>{$label_m}</span><span><strong>" . htmlspecialchars((string)$v) . "</strong></span></div>";
        }

        $issuesHtml = '';
        $topIssues = array_filter($result['issues'] ?? [], fn($i) => in_array($i['severity'], ['CRITICAL', 'HIGH']));
        foreach (array_slice(array_values($topIssues), 0, 3) as $issue) {
            $msg = htmlspecialchars($issue['message']);
            $file = htmlspecialchars($issue['file']);
            $issuesHtml .= "<div class='issue issue-{$issue['severity']}'><div class='issue-file'>{$file}</div>{$msg}</div>";
        }

        $owaspHtml = '';
        if ($key === 'owasp' && !empty($result['metrics']['owasp_breakdown'])) {
            $owaspHtml = "<table class='owasp-table'><tr><th>Code</th><th>Category</th><th>Score</th><th>Risk</th></tr>";
            foreach ($result['metrics']['owasp_breakdown'] as $code => $owasp) {
                $col = $owasp['score'] >= 80 ? '#22c55e' : ($owasp['score'] >= 60 ? '#f59e0b' : '#ef4444');
                $owaspHtml .= "<tr><td>{$code}</td><td>{$owasp['name']}</td><td style='color:{$col};font-weight:600'>{$owasp['score']}</td><td><span class='risk-badge risk-{$owasp['risk']}'>{$owasp['risk']}</span></td></tr>";
            }
            $owaspHtml .= "</table>";
        }

        $recsHtml = '';
        foreach (array_slice($result['recommendations'] ?? [], 0, 2) as $rec) {
            $recsHtml .= "<div class='rec'>" . htmlspecialchars($rec) . "</div>";
        }

        return <<<HTML
<div class="card">
  <div class="card-title">{$label}</div>
  <div class="score-row">
    <span class="score-num" style="color:{$color}">{$score}</span>
    <span style="color:#64748b">/100</span>
    <span class="risk-badge risk-{$risk}">{$risk}</span>
  </div>
  <div class="progress-bar"><div class="progress-fill" style="width:{$score}%;background:{$color}"></div></div>
  <div style="font-size:0.82rem;color:#94a3b8;margin:0.5rem 0">{$summary}</div>
  {$metricsHtml}
  {$issuesHtml}
  {$owaspHtml}
  {$recsHtml}
</div>
HTML;
    }

    // ─────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────

    /**
     * Merges file_issues from all analyzer results into one map,
     * tagging each issue with the module name it came from.
     *
     * @return array<string, array<int, array>>
     */
    private function aggregateFileIssues(): array
    {
        $merged = [];

        foreach ($this->results as $module => $result) {
            if (isset($result['error']) || empty($result['file_issues'])) {
                continue;
            }
            $label = self::SECTION_LABELS[$module] ?? $module;

            foreach ($result['file_issues'] as $file => $issues) {
                foreach ($issues as $issue) {
                    $issue['module'] = $label;
                    $merged[$file][] = $issue;
                }
            }
        }

        return $merged;
    }

    private function calculateGlobalScore(): float
    {
        $scores = array_filter(array_column($this->results, 'score'));
        return empty($scores) ? 0 : round(array_sum($scores) / count($scores), 1);
    }

    private function getGrade(float $score): string
    {
        return match(true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B',
            $score >= 60 => 'C',
            $score >= 50 => 'D',
            default      => 'F',
        };
    }

    private function progressBar(float $score, bool $noColor = false): string
    {
        $filled = (int)($score / 5);
        $empty  = 20 - $filled;
        $bar    = str_repeat('█', $filled) . str_repeat('░', $empty);

        if ($noColor) return "[{$bar}] " . sprintf("%.1f%%", $score);

        $color = $score >= 80 ? 'green' : ($score >= 60 ? 'yellow' : 'red');
        return "[" . $this->color($bar, $color) . "] " . sprintf("%.1f%%", $score);
    }

    private function color(string $text, string $color): string
    {
        $colors = [
            'red'    => "\033[0;31m",
            'green'  => "\033[0;32m",
            'yellow' => "\033[1;33m",
            'cyan'   => "\033[0;36m",
            'white'  => "\033[1;37m",
            'reset'  => "\033[0m",
        ];
        return ($colors[$color] ?? '') . $text . ($colors['reset']);
    }
}
