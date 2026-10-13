#!/usr/bin/env bash
# ============================================================================
# AurumFlow — menjalankan seluruh QC (Fase 1 + Fase 2 + Fase 3 + Fase 4 + Fase 5 + Fase 6)
# ============================================================================
# Urutan bersifat sengaja:
#   1. qc_pdo               — memastikan lapisan database sehat lebih dulu
#   2. qc_formatter         — murni, tidak menyentuh database
#   3. qc_security          — murni, tidak menyentuh database
#   4. qc_seed              — membangun skema ERD, seed, dan akun operator
#   5. qc_roadmap_protocol_1 — Protokol QC Manual 1 dari dokumen Roadmap
#   6. qc_fase_2            — Protokol QC Manual 2 (menulis kas & transaksi hulu)
#   7. qc_fase_3            — Protokol QC Manual 3 (menulis settlement + berkas nota)
#   8. qc_fase_4            — Protokol QC Manual 4 (dompet modal, distribusi, penarikan)
#   9. qc_fase_5            — Protokol QC Manual 5 (undangan, PIN, dasbor blind, PWA)
#  10. qc_fase_6            — Protokol QC Manual 6A (dana talangan + isolasi pool mitra)
#  11. qc_sql_injection     — butuh akun operator untuk uji lewat HTTP
#  12. qc_routing           — butuh akun operator untuk uji login
#
# Pemakaian:
#   bash tests/run_qc.sh
#
# Exit code 0 hanya bila seluruh suite lulus.
# ============================================================================

set -u
# Tanpa `pipefail`, status pipeline diambil dari `tee` (selalu 0), sehingga
# suite yang gagal tetap dihitung "LULUS" dan skrip ini `exit 0`.
set -o pipefail

cd "$(dirname "$0")/.." || exit 1

LOG_FILE="storage/logs/qc-fase6.log"
mkdir -p storage/logs

SUITES=(
    "qc_pdo"
    "qc_formatter"
    "qc_security"
    "qc_seed"
    "qc_roadmap_protocol_1"
    "qc_fase_2"
    "qc_fase_3"
    "qc_fase_4"
    "qc_fase_5"
    "qc_fase_6"
    "qc_sql_injection"
    "qc_routing"
)

FAILED=()
PASSED=()

{
    echo "================================================================"
    echo "AurumFlow — QC (Fase 1 + Fase 2 + Fase 3 + Fase 4 + Fase 5 + Fase 6)"
    echo "Dijalankan : $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "PHP        : $(php -r 'echo PHP_VERSION;')"
    echo "Database   : $(php -r 'require "app/Config/bootstrap.php"; echo AurumFlow\Config\Env::get("DB_NAME"), " @ ", AurumFlow\Config\Env::get("DB_HOST");') "
    echo "================================================================"
} | tee "$LOG_FILE"

for suite in "${SUITES[@]}"; do
    if php "tests/${suite}.php" 2>&1 | tee -a "$LOG_FILE"; then
        PASSED+=("$suite")
    else
        FAILED+=("$suite")
    fi
done

{
    echo
    echo "================================================================"
    echo "RINGKASAN QC (FASE 1 + FASE 2 + FASE 3 + FASE 4 + FASE 5 + FASE 6)"
    echo "================================================================"
    echo "LULUS : ${#PASSED[@]}/${#SUITES[@]}  ${PASSED[*]:-}"
    echo "GAGAL : ${#FAILED[@]}/${#SUITES[@]}  ${FAILED[*]:-}"
    echo "Log   : ${LOG_FILE}"
    echo "================================================================"
} | tee -a "$LOG_FILE"

if [ ${#FAILED[@]} -gt 0 ]; then
    exit 1
fi

exit 0
