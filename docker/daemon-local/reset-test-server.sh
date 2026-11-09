#!/usr/bin/env bash
# Stop and remove only the disposable machine enrolled with daemon-local-token.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
docker stop serveros-daemon-test >/dev/null 2>&1 || true

php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $path = storage_path("app/private/daemon-local-token");
  $plain = @file_get_contents($path);
  if ($plain === false) { exit(0); }
  $enrolment = App\Models\DaemonEnrolment::query()
    ->where("token_hash", hash("sha256", trim($plain)))->first();
  if ($enrolment !== null) {
    if ($enrolment->machine_id !== null) {
      App\Models\Machine::query()->whereKey($enrolment->machine_id)->delete();
    }
    $enrolment->delete();
  }
  unlink($path);
'
echo 'Disposable daemon server and its dashboard record removed.'
