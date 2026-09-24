<?php

declare(strict_types=1);

namespace AurumFlow\Config;

/**
 * Exception yang dilempar lapisan database.
 *
 * Dipisahkan dari Database.php agar dapat di-autoload sendiri dan tetap
 * dapat ditangkap meskipun konstruksi koneksi gagal di tengah jalan.
 *
 * Pesan yang sampai ke pengguna SELALU generik. Detail teknis (DSN, nama
 * host, pesan server) hanya masuk ke storage/logs — lihat risiko kebocoran
 * kredensial pada dokumen rencana.
 */
class DatabaseException extends \RuntimeException
{
}
