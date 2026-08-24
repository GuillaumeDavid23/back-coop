<?php

namespace App\Service\Log;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit et décode les fichiers de log Monolog pour l'explorateur du BO.
 *
 * Ne charge jamais tout le fichier : seuls les derniers Mo sont lus, ce qui
 * garde l'écran utilisable même avec un payment.log de plusieurs centaines de
 * Mo en production.
 */
final class LogReader
{
    private const int MAX_BYTES = 3_000_000;

    public function __construct(
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /** @return array<string, array{label: string, path: string, exists: bool, size: int, mtime: ?int}> */
    public function availableFiles(): array
    {
        $candidates = [
            'payment' => ['label' => 'Paiements / Stripe / Facturation', 'file' => 'payment.log'],
            'app' => ['label' => 'Application', 'file' => $this->environment.'.log'],
        ];

        // Tout autre .log présent (rotation, canal ajouté plus tard) reste consultable.
        foreach (glob($this->logsDir.'/*.log') ?: [] as $path) {
            $file = basename($path);
            if (\in_array($file, array_column($candidates, 'file'), true)) {
                continue;
            }
            $candidates[pathinfo($file, \PATHINFO_FILENAME)] = ['label' => $file, 'file' => $file];
        }

        $files = [];
        foreach ($candidates as $key => $meta) {
            $path = $this->logsDir.'/'.$meta['file'];
            $exists = is_file($path);
            $files[$key] = [
                'label' => $meta['label'],
                'path' => $path,
                'exists' => $exists,
                'size' => $exists ? (filesize($path) ?: 0) : 0,
                'mtime' => $exists ? (filemtime($path) ?: null) : null,
            ];
        }

        return $files;
    }

    /**
     * @return array{entries: LogEntry[], stats: array<string,int>, channels: string[], total: int, truncated: bool}
     */
    public function read(string $path, LogQuery $query): array
    {
        if (!is_file($path)) {
            return ['entries' => [], 'stats' => [], 'channels' => [], 'total' => 0, 'truncated' => false];
        }

        [$content, $truncated] = $this->readTail($path);
        $all = $this->parse($content);

        // Les stats et la liste des canaux portent sur tout ce qui a été lu,
        // pas seulement sur la page filtrée : c'est ce qui permet de voir
        // "il y a 12 ERROR" avant même de filtrer dessus.
        $stats = [];
        $channels = [];
        foreach ($all as $entry) {
            $stats[$entry->level] = ($stats[$entry->level] ?? 0) + 1;
            $channels[$entry->channel] = true;
        }
        ksort($channels);

        $matching = array_values(array_filter($all, $query->matches(...)));
        $total = \count($matching);

        // Les plus récentes d'abord.
        $matching = array_reverse($matching);
        $entries = \array_slice($matching, 0, $query->limit);

        return [
            'entries' => $entries,
            'stats' => $stats,
            'channels' => array_keys($channels),
            'total' => $total,
            'truncated' => $truncated,
        ];
    }

    /** @return array{0: string, 1: bool} */
    private function readTail(string $path): array
    {
        $size = filesize($path) ?: 0;
        if ($size <= self::MAX_BYTES) {
            return [(string) file_get_contents($path), false];
        }

        $handle = fopen($path, 'rb');
        fseek($handle, -self::MAX_BYTES, \SEEK_END);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        // La première ligne est probablement tronquée au milieu : on la jette.
        $newline = strpos($content, "\n");

        return [false === $newline ? $content : substr($content, $newline + 1), true];
    }

    /** @return LogEntry[] */
    private function parse(string $content): array
    {
        $entries = [];
        $header = '/^\[(?<date>[^\]]+)\] (?<channel>[^.\s]+)\.(?<level>[A-Z]+): (?<rest>.*)$/s';

        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }

            if (!preg_match($header, $line, $m)) {
                // Continuation (stack trace sur plusieurs lignes) : on la rattache
                // à l'entrée précédente plutôt que de la perdre.
                if ([] !== $entries) {
                    $previous = array_pop($entries);
                    $entries[] = new LogEntry(
                        $previous->date,
                        $previous->channel,
                        $previous->level,
                        $previous->message."\n".$line,
                        $previous->context,
                        $previous->extra,
                        $previous->raw."\n".$line,
                    );

                    continue;
                }

                $entries[] = new LogEntry(null, 'unknown', 'UNKNOWN', $line, null, null, $line);

                continue;
            }

            $rest = $m['rest'];
            [$rest, $extra] = $this->pullTrailingJson($rest);
            [$message, $context] = $this->pullTrailingJson($rest);

            try {
                $date = new \DateTimeImmutable($m['date']);
            } catch (\Exception) {
                $date = null;
            }

            $entries[] = new LogEntry(
                $date,
                $m['channel'],
                $m['level'],
                trim($message),
                $context,
                $extra,
                $line,
            );
        }

        return $entries;
    }

    /**
     * Détache le dernier bloc JSON d'une ligne (Monolog écrit "message {context} {extra}").
     * Scanne depuis la fin en comptant les accolades pour ne pas se faire piéger
     * par une accolade présente dans le message lui-même.
     *
     * @return array{0: string, 1: ?array}
     */
    private function pullTrailingJson(string $line): array
    {
        $line = rtrim($line);
        $length = \strlen($line);
        if (0 === $length) {
            return [$line, null];
        }

        $close = $line[$length - 1];
        $open = match ($close) {
            '}' => '{',
            ']' => '[',
            default => null,
        };

        if (null === $open) {
            return [$line, null];
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $length - 1; $i >= 0; --$i) {
            $char = $line[$i];

            if ($inString) {
                // On remonte la chaîne à l'envers : un guillemet ferme la chaîne
                // sauf s'il est échappé (nombre impair d'antislashs devant).
                if ('"' === $char && !$this->isEscapedAt($line, $i)) {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inString = true;

                continue;
            }

            if ($char === $close) {
                ++$depth;
            } elseif ($char === $open) {
                --$depth;
                if (0 === $depth) {
                    $json = substr($line, $i);
                    $decoded = json_decode($json, true);

                    if (!\is_array($decoded)) {
                        return [$line, null];
                    }

                    return [rtrim(substr($line, 0, $i)), $decoded];
                }
            }
        }

        unset($escaped);

        return [$line, null];
    }

    private function isEscapedAt(string $line, int $position): bool
    {
        $backslashes = 0;
        for ($i = $position - 1; $i >= 0 && '\\' === $line[$i]; --$i) {
            ++$backslashes;
        }

        return 1 === $backslashes % 2;
    }
}
