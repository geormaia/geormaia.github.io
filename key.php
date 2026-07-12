<?php
declare(strict_types=1);

/* Key Finder — arquivo único, PHP 8+ */

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeAccidentals(string $text): string
{
    return str_replace(['♯', '♭', '𝄪', '𝄫'], ['#', 'b', '##', 'bb'], $text);
}

function notePc(string $note): ?int
{
    $note = normalizeAccidentals(trim($note));
    if (!preg_match('/^([A-Ga-g])([#b]{0,2})$/', $note, $m)) return null;
    $base = ['C'=>0,'D'=>2,'E'=>4,'F'=>5,'G'=>7,'A'=>9,'B'=>11][strtoupper($m[1])];
    foreach (str_split($m[2]) as $acc) $base += $acc === '#' ? 1 : -1;
    return ($base % 12 + 12) % 12;
}

function chordQuality(string $suffix): string
{
    $s = strtolower(str_replace(['(', ')', ' ', '7m'], ['', '', '', 'maj7'], $suffix));
    if (preg_match('/^(dim|°|o)/u', $s)) return 'dim';
    if (preg_match('/^(aug|\+)/', $s)) return 'aug';
    if (preg_match('/^(sus)/', $s)) return 'sus';
    if (preg_match('/^m(?!aj)/', $s)) return 'min';
    return 'maj';
}

function extractChords(string $text): array
{
    $text = normalizeAccidentals($text);
    $text = preg_replace('/\{(?:comment|c|title|t|key|artist|subtitle)\s*:[^}]*}/iu', ' ', $text) ?? $text;
    preg_match_all('/(?<![\pL\pN#b])([A-Ga-g](?:#{1,2}|b{1,2})?)(?![a-z])((?:7M|maj|min|dim|aug|sus|add|m|M|Δ|°|ø|\+|-)?(?:\d{0,2})?(?:[#b]\d+)*(?:\([^)]*\))?)(?:\/([A-Ga-g](?:#{1,2}|b{1,2})?))?(?![\pL\pN])/u', $text, $matches, PREG_SET_ORDER);

    $result = [];
    foreach ($matches as $m) {
        $root = notePc($m[1]);
        if ($root === null) continue;
        $raw = $m[1] . ($m[2] ?? '') . (!empty($m[3]) ? '/' . $m[3] : '');
        $result[] = ['raw'=>$raw, 'root'=>$root, 'quality'=>chordQuality($m[2] ?? '')];
    }
    return $result;
}

function keyName(int $pc, string $mode): string
{
    $names = ['C','C#','D','Eb','E','F','F#','G','Ab','A','Bb','B'];
    return $names[$pc] . ($mode === 'min' ? 'm' : '');
}

function analyzeKey(array $chords): array
{
    if (!$chords) return [];
    $profiles = [
        'maj' => [[0,'maj'],[2,'min'],[4,'min'],[5,'maj'],[7,'maj'],[9,'min'],[11,'dim']],
        'min' => [[0,'min'],[2,'dim'],[3,'maj'],[5,'min'],[7,'min'],[8,'maj'],[10,'maj']],
    ];
    $scores = [];
    $count = count($chords);

    foreach (['maj','min'] as $mode) {
        for ($tonic = 0; $tonic < 12; $tonic++) {
            $score = 0.0; $matched = 0;
            foreach ($chords as $i => $chord) {
                $degree = ($chord['root'] - $tonic + 12) % 12;
                $expected = null;
                foreach ($profiles[$mode] as [$interval, $quality]) {
                    if ($degree === $interval) { $expected = $quality; break; }
                }
                if ($expected !== null) {
                    $matched++;
                    if ($chord['quality'] === $expected || $chord['quality'] === 'sus') $score += 3.0;
                    elseif (($expected === 'dim' && $chord['quality'] === 'min') || ($expected === 'min' && $chord['quality'] === 'maj' && $degree === 7)) $score += 1.7;
                    else $score += 0.5;
                } else $score -= 1.1;

                if ($degree === 0) $score += 1.5;
                if (($i === 0 || $i === $count - 1) && $degree === 0) $score += 2.2;
                if ($mode === 'min' && $degree === 7 && $chord['quality'] === 'maj') $score += 2.3; // V maior da menor harmônica
            }
            for ($i = 0; $i < $count - 1; $i++) {
                $a = ($chords[$i]['root'] - $tonic + 12) % 12;
                $b = ($chords[$i+1]['root'] - $tonic + 12) % 12;
                if ($a === 7 && $b === 0) $score += 3.4;
            }
            $scores[] = ['tonic'=>$tonic, 'mode'=>$mode, 'score'=>$score, 'matched'=>$matched];
        }
    }
    usort($scores, fn($a,$b) => $b['score'] <=> $a['score']);
    $best = $scores[0]['score'];
    foreach ($scores as &$row) {
        $distance = max(0.0, $best - $row['score']);
        $row['confidence'] = (int)round(max(18, min(98, 94 - $distance * 7)));
        $row['name'] = keyName($row['tonic'], $row['mode']);
        $row['coverage'] = (int)round($row['matched'] / max(1, $count) * 100);
    }
    return array_slice($scores, 0, 3);
}

$input = trim((string)($_POST['chords'] ?? ''));
$chords = $input !== '' ? extractChords($input) : [];
$results = $input !== '' ? analyzeKey($chords) : [];
$unique = [];
foreach ($chords as $chord) $unique[$chord['raw']] = true;
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#09090b">
  <meta name="description" content="Descubra a tonalidade de uma música a partir dos acordes.">
  <title>Key Finder — Descubra o tom da música</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@400;500;600;700&amp;family=Inter:wght@400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
  <style type="text/tailwindcss">
    @theme {
      --font-sans: "Inter", ui-sans-serif, system-ui, sans-serif;
      --font-mono: "Geist Mono", ui-monospace, SFMono-Regular, monospace;
    }
  </style>
  <link href="https://cdn.jsdelivr.net/npm/flowbite@4.0.1/dist/flowbite.min.css" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased selection:bg-lime-300 selection:text-zinc-950">
  <div class="pointer-events-none fixed inset-x-0 top-0 -z-0 h-96 bg-[radial-gradient(circle_at_top,rgba(163,230,53,0.10),transparent_65%)]"></div>

  <main class="relative z-10 mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 sm:py-14 lg:px-8">
    <header class="mb-12 flex items-center justify-between">
      <a href="<?= h((string)($_SERVER['PHP_SELF'] ?? 'key-finder.php')) ?>" class="group flex items-center gap-3" aria-label="Página inicial">
        <span class="grid size-11 place-items-center rounded-2xl bg-lime-300 text-zinc-950 shadow-lg shadow-lime-300/10 transition group-hover:rotate-3">
          <i data-lucide="audio-lines" class="size-5" aria-hidden="true"></i>
        </span>
        <span>
          <strong class="block text-sm font-bold tracking-tight">Key Finder</strong>
          <span class="block text-xs text-zinc-500">Analisador de tonalidade</span>
        </span>
      </a>
      <span class="hidden items-center gap-2 rounded-full border border-zinc-800 bg-zinc-900/70 px-3 py-1.5 text-xs font-medium text-zinc-400 sm:flex">
        <span class="size-1.5 rounded-full bg-lime-300"></span> PHP 8.3
      </span>
    </header>

    <section class="mb-9 max-w-3xl">
      <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-lime-300/20 bg-lime-300/5 px-3 py-1 text-xs font-semibold text-lime-300">
        <i data-lucide="sparkles" class="size-3.5" aria-hidden="true"></i>
        Análise harmônica
      </div>
      <h1 class="text-4xl font-black leading-[0.98] tracking-[-0.045em] text-white sm:text-6xl lg:text-7xl">
        Descubra o <span class="text-lime-300">tom</span><br class="hidden sm:block"> pelos acordes.
      </h1>
      <p class="mt-5 max-w-2xl text-base leading-7 text-zinc-400 sm:text-lg">
        Cole uma sequência, uma cifra completa ou texto ChordPro. O sistema analisa o campo harmônico e as resoluções para encontrar a tonalidade mais provável.
      </p>
    </section>

    <form method="post" class="overflow-hidden rounded-3xl border border-zinc-800 bg-zinc-900/80 p-2 shadow-2xl shadow-black/30 backdrop-blur-sm">
      <label for="chords" class="sr-only">Acordes da música</label>
      <textarea id="chords" name="chords" spellcheck="false" required autofocus
        class="block min-h-64 w-full resize-y rounded-2xl border-0 bg-zinc-950 p-5 font-mono text-sm leading-7 text-zinc-100 placeholder:text-zinc-700 focus:ring-2 focus:ring-lime-300/50 sm:p-6 sm:text-base"
        placeholder="Exemplo:&#10;| Am7 | G | F7M |&#10;| Dm7 | E E4 E |"><?= h($input) ?></textarea>
      <div class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="flex items-center gap-2 text-xs text-zinc-500">
          <i data-lucide="info" class="size-3.5 shrink-0" aria-hidden="true"></i>
          Aceita Am, F7M, G/B, C#sus4 e Bb7(9)
        </p>
        <div class="flex gap-2">
          <?php if ($input !== ''): ?>
            <a href="<?= h((string)($_SERVER['PHP_SELF'] ?? 'key-finder.php')) ?>" class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-zinc-400 transition hover:bg-zinc-800 hover:text-white sm:flex-none">
              <i data-lucide="rotate-ccw" class="size-4" aria-hidden="true"></i> Limpar
            </a>
          <?php endif; ?>
          <button type="submit" class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-lime-300 px-5 py-3 text-sm font-bold text-zinc-950 transition hover:bg-lime-200 focus:outline-none focus:ring-4 focus:ring-lime-300/20 sm:flex-none">
            <i data-lucide="search" class="size-4" aria-hidden="true"></i> Encontrar tom
          </button>
        </div>
      </div>
    </form>

    <?php if ($input !== '' && !$chords): ?>
      <div class="mt-5 flex items-start gap-3 rounded-2xl border border-red-900/60 bg-red-950/40 p-4 text-sm text-red-200" role="alert">
        <i data-lucide="circle-alert" class="mt-0.5 size-5 shrink-0" aria-hidden="true"></i>
        <p><strong class="font-bold">Nenhum acorde reconhecido.</strong> Use a notação por letras, como <span class="font-mono">Am G F E</span>.</p>
      </div>
    <?php elseif ($results): $best = $results[0]; ?>
      <section class="mt-6 grid gap-4 lg:grid-cols-5" aria-label="Resultado da análise">
        <article class="rounded-3xl border border-zinc-800 bg-zinc-900/80 p-6 lg:col-span-3 sm:p-8">
          <div class="flex items-center justify-between gap-4">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-zinc-500">Tom mais provável</p>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-lime-300/10 px-2.5 py-1 font-mono text-xs font-semibold text-lime-300">
              <i data-lucide="badge-check" class="size-3.5" aria-hidden="true"></i><?= $best['confidence'] ?>% de confiança
            </span>
          </div>
          <div class="my-5 font-mono text-7xl font-black leading-none tracking-[-0.07em] text-lime-300 sm:text-8xl"><?= h($best['name']) ?></div>
          <p class="text-sm leading-6 text-zinc-400">
            <?= $best['mode'] === 'min' ? 'Tonalidade menor' : 'Tonalidade maior' ?> · <?= $best['coverage'] ?>% dos acordes pertencem ao campo harmônico
          </p>
          <progress class="mt-4 h-2 w-full overflow-hidden rounded-full accent-lime-300" value="<?= $best['confidence'] ?>" max="100" aria-label="Confiança da análise"></progress>

          <div class="mt-7 border-t border-zinc-800 pt-6">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-zinc-500">Outras possibilidades</p>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
              <?php foreach (array_slice($results, 1) as $result): ?>
                <div class="flex items-center justify-between rounded-xl bg-zinc-950/70 px-4 py-3">
                  <strong class="font-mono text-sm text-zinc-200"><?= h($result['name']) ?></strong>
                  <span class="text-xs font-medium text-zinc-500"><?= $result['confidence'] ?>%</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </article>

        <aside class="rounded-3xl border border-zinc-800 bg-zinc-900/80 p-6 lg:col-span-2 sm:p-8">
          <div class="flex items-center gap-2">
            <i data-lucide="music-2" class="size-4 text-lime-300" aria-hidden="true"></i>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-zinc-500">Acordes reconhecidos</p>
          </div>
          <div class="mt-5 flex flex-wrap gap-2">
            <?php foreach (array_keys($unique) as $name): ?>
              <span class="rounded-lg border border-zinc-800 bg-zinc-950 px-3 py-2 font-mono text-xs font-semibold text-zinc-300"><?= h($name) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="mt-6 flex gap-3 rounded-2xl bg-zinc-950/70 p-4">
            <i data-lucide="lightbulb" class="mt-0.5 size-4 shrink-0 text-amber-300" aria-hidden="true"></i>
            <p class="text-xs leading-5 text-zinc-500">Modulações, empréstimos modais e cifras muito curtas podem gerar mais de uma tonalidade possível.</p>
          </div>
        </aside>
      </section>
    <?php endif; ?>

    <footer class="mt-8 flex flex-col items-center justify-between gap-3 border-t border-zinc-900 pt-6 text-xs text-zinc-600 sm:flex-row">
      <p>Sem banco de dados e sem API externa.</p>
      <p class="flex items-center gap-1.5"><i data-lucide="shield-check" class="size-3.5" aria-hidden="true"></i> Seus acordes não são armazenados</p>
    </footer>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/flowbite@4.0.1/dist/flowbite.min.js"></script>
  <script>document.addEventListener('DOMContentLoaded', () => window.lucide?.createIcons());</script>
</body>
</html>
