<?php

namespace App\Biometric;

use Carbon\CarbonImmutable;

/**
 * ZKTeco's ADMS ("PUSH") protocol, the cloud mode of ZKTeco attendance
 * devices and their OEM rebrands (eSSL and others): the device calls us over
 * HTTP on /iclock/*, we never call it. Only text in and text out lives here;
 * App\Http\Controllers\Biometric\AdmsController does the rest.
 *
 *  - GET  /iclock/cdata?SN=..&options=all   handshake: we answer the options below
 *  - POST /iclock/cdata?SN=..&table=ATTLOG  punches, one per line: PIN \t time \t status \t verify \t workcode ...
 *  - POST /iclock/cdata?SN=..&table=OPERLOG user and fingerprint records (USER PIN=.., FP PIN=..)
 *  - GET  /iclock/getrequest?SN=..          our queued commands, "C:<id>:<command>" per line, or "OK"
 *  - POST /iclock/devicecmd?SN=..           the device's answers, "ID=..&Return=..&CMD=.." per line
 *
 * Formats follow ZKTeco's PUSH SDK documents and the open-source servers built
 * on them (tested there on ZAM180 and SpeedFace devices). Not yet tried on a
 * device here: check a real one before relying on the fingerprint copy-back.
 */
class Adms
{
    /** The handshake: what the device should send us and how often. */
    public static function options(string $serial, ?string $attStamp, ?string $opStamp): string
    {
        return implode("\n", [
            'GET OPTION FROM: '.$serial,
            'ATTLOGStamp='.($attStamp ?: 'None'),
            'OPERLOGStamp='.($opStamp ?: 'None'),
            'ATTPHOTOStamp=None',
            'ErrorDelay=30',
            'Delay=10',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=TransData AttLog OpLog EnrollUser ChgUser EnrollFP ChgFP',
            'TimeZone=5',
            'Realtime=1',
            'Encrypt=None',
        ])."\n";
    }

    /**
     * Punches from an ATTLOG upload. The time is the device's clock, which the
     * shop keeps on Pakistan time.
     *
     * @return list<array{pin: int, at: CarbonImmutable, status: int, verify: int}>
     */
    public static function punches(string $body): array
    {
        $punches = [];

        foreach (self::lines($body) as $line) {
            $fields = explode("\t", $line);

            if (count($fields) < 2 || ! ctype_digit(trim($fields[0]))) {
                continue;
            }

            try {
                $at = ctype_digit(trim($fields[1]))
                    ? CarbonImmutable::createFromTimestamp((int) trim($fields[1]), 'Asia/Karachi')
                    : CarbonImmutable::createFromFormat('Y-m-d H:i:s', trim($fields[1]), 'Asia/Karachi');
            } catch (\Throwable) {
                continue;
            }

            if ($at === false || $at === null) {
                continue;
            }

            $punches[] = ['pin' => (int) trim($fields[0]), 'at' => $at, 'status' => (int) ($fields[2] ?? 0), 'verify' => (int) ($fields[3] ?? 0)];
        }

        return $punches;
    }

    /**
     * Records in an OPERLOG upload: "USER PIN=1\tName=Ali..." and
     * "FP PIN=1\tFID=6\tSize=1184\tValid=1\tTMP=...". Others (OPLOG) are skipped.
     *
     * @return list<array{type: string, fields: array<string, string>}>
     */
    public static function records(string $body): array
    {
        $records = [];

        foreach (self::lines($body) as $line) {
            if (! preg_match('/^(USER|FP|BIODATA)\s+(.*)$/s', $line, $m)) {
                continue;
            }

            $fields = [];

            foreach (explode("\t", $m[2]) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $fields[strtoupper(trim($key))] = $value;
            }

            $records[] = ['type' => $m[1], 'fields' => $fields];
        }

        return $records;
    }

    /**
     * Answers from POST devicecmd.
     *
     * @return list<array{id: int, return: int}>
     */
    public static function answers(string $body): array
    {
        $answers = [];

        foreach (self::lines($body) as $line) {
            parse_str($line, $fields);

            if (isset($fields['ID']) && ctype_digit((string) $fields['ID'])) {
                $answers[] = ['id' => (int) $fields['ID'], 'return' => (int) ($fields['Return'] ?? 0)];
            }
        }

        return $answers;
    }

    /** Queued commands as the device reads them from getrequest. */
    public static function commands(iterable $commands): string
    {
        $lines = [];

        foreach ($commands as $command) {
            $lines[] = 'C:'.$command->id.':'.$command->command;
        }

        return $lines === [] ? "OK\n" : implode("\n", $lines)."\n";
    }

    /** Adds or updates a user on the device. Names are kept to plain characters the device can show. */
    public static function userCommand(int $pin, string $name): string
    {
        $name = mb_substr(trim(preg_replace('/[\t\r\n=]+/', ' ', $name)), 0, 24);

        return "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPri=0\tPasswd=\tCard=\tGrp=1";
    }

    public static function deleteUserCommand(int $pin): string
    {
        return "DATA DELETE USERINFO PIN={$pin}";
    }

    /** Puts a stored fingerprint back on the device after the user is re-added. */
    public static function fingerCommand(int $pin, int $finger, int $size, string $template): string
    {
        return "DATA UPDATE FINGERTMP PIN={$pin}\tFID={$finger}\tSize={$size}\tValid=1\tTMP={$template}";
    }

    /** @return list<string> */
    protected static function lines(string $body): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $body) ?: []), fn ($l) => $l !== ''));
    }
}
