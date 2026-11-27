# Fingerprint devices (gyms)

Gyms and trainers can let members check in by fingerprint. The device sends
each punch to us over the internet, and it becomes a check-in with the
member's membership standing and dues copied on (`App\Actions\CheckIns`).

## Which devices

ZKTeco attendance and access devices that have **ADMS** (shown on the device
as *Cloud Server Setting*, *ADMS* or *Push*), and OEM rebrands of them such as
eSSL. Common models sold in Pakistan (K40, F18, MB360, iClock series, SpeedFace)
have it on most firmware, but not all: check the device menu before buying.
Devices that only speak the older "pull" SDK (TCP port 4370) cannot reach a
cloud app and need a PC on the gym's network; they are not supported.

## Setting one up

1. Fingerprint Devices › Add a device: a name and the device's **serial number**
   (Menu › System Info › Device Info, "SN"). The serial is the only thing the
   protocol identifies a device by, so we only answer serials a shop registered,
   whose shop uses fingerprint check-in, and that are switched on.
2. On the device: Menu › Comm › Cloud Server Setting.
   - Server address: the app's host name (APP_URL's host).
   - Port: 80. Turn HTTPS on only if the device supports it.
   - The device then calls `/iclock/cdata`, `/iclock/getrequest` and `/iclock/devicecmd`.
3. Keep the device clock on Pakistan time: punch times are read as Asia/Karachi.
4. Members › To device gives the member a member number and sends them to the
   device as a user with that PIN. Enrol the finger on the device under that
   number (User Mgt › the user › Fingerprint).

## Staff on the same device, and QR cards

- Staff clock in on the same device. Staff Attendance › To device gives them an
  attendance PIN from the **same number range** as members' member numbers (the
  device knows everyone by PIN alone), so the two never collide. A punch with a
  staff PIN is attendance (`App\Actions\StaffAttendance`); any other is a member check-in.
- Members without a fingerprint, or gyms without a device: print a **member card**
  (Check-ins › Card) or a **staff badge** (Staff Attendance › Badge). The QR holds
  `HOKM:` or `HOKS:` and a random code; any USB/Bluetooth barcode scanner that reads QR
  types it into the scan box on Check-ins or Staff Attendance. Codes only work in their
  own shop.

## Deploy notes

- `/iclock/*` must be reachable over plain **HTTP** without a redirect to HTTPS,
  unless every device in use supports HTTPS. Many ADMS firmwares don't follow
  redirects or speak TLS. Add an exception for `/iclock/` to any HTTP→HTTPS
  redirect and to any CDN/WAF bot protection in front of the app.
- No `.env` key is needed. The only per-device setting is the serial number,
  typed in by the shop.
- The scheduler runs `members:sync-devices` nightly for devices set to take
  expired members off.

## Protocol (what we implement)

| Device call | We answer |
| --- | --- |
| `GET /iclock/cdata?SN=..&options=all` | `GET OPTION FROM: SN`, then `ATTLOGStamp`, `OPERLOGStamp`, `ErrorDelay=30`, `Delay=10`, `TransTimes`, `TransInterval=1`, `TransFlag=...`, `TimeZone=5`, `Realtime=1`, `Encrypt=None` (one per line) |
| `POST /iclock/cdata?SN=..&table=ATTLOG&Stamp=..` | Lines `PIN\tYYYY-MM-DD HH:MM:SS\tstatus\tverify\t...`; we answer `OK: n` (n new punches) |
| `POST /iclock/cdata?SN=..&table=OPERLOG` | `USER PIN=..\tName=..` and `FP PIN=..\tFID=..\tSize=..\tValid=..\tTMP=..`; fingerprint templates are kept (encrypted) |
| `GET /iclock/getrequest?SN=..` | Queued commands, `C:<id>:<command>` one per line, up to 10, or `OK` |
| `POST /iclock/devicecmd?SN=..` | Lines `ID=..&Return=..&CMD=..`; Return 0 is success; we answer `OK` |

Commands we send: `DATA UPDATE USERINFO PIN=n\tName=..\tPri=0\tPasswd=\tCard=\tGrp=1`,
`DATA DELETE USERINFO PIN=n`, and `DATA UPDATE FINGERTMP PIN=n\tFID=f\tSize=s\tValid=1\tTMP=..`
to put a removed member back with their stored finger.

Sources: ZKTeco PUSH SDK communication protocol documents and the open-source
servers built on them (s0x90/zkteco-adms, tested on ZAM180 and SpeedFace; the
Laravel packages dewanlab/laravel-zkteco-adms-core and
syofyanzuhad/filament-zkteco-adms). **Not yet tried against a real device
here.** Before relying on it, connect one device and check: the handshake is
accepted, punches arrive, a user command returns `Return=0`, and a removed
member's fingerprint is restored by FINGERTMP (some firmwares use `BIODATA`
instead; if so, the template upload and restore need that table).

## Privacy

Fingerprint templates are biometric data. They are stored encrypted
(`biometric_templates.template`, Laravel `encrypted` cast), only for shops that
turn on "take expired members off", and deleted with the shop.
