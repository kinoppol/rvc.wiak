<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Upload;
use App\Models\User;

final class AvatarController extends Controller
{
    public function show(array $args): void
    {
        $this->requireLogin();

        $user = User::findById((int) $args['id']);
        $path = $user['avatar_path'] ?? null;
        if (!$path) {
            http_response_code(404);
            exit;
        }

        $abs = Upload::absolutePath($path);
        if (!is_file($abs)) {
            http_response_code(404);
            exit;
        }

        $mime = match (strtolower(pathinfo($abs, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string) filesize($abs));
        readfile($abs);
        exit;
    }
}
