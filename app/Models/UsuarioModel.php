<?php

namespace App\Models;

use CodeIgniter\Model;

class UsuarioModel extends Model
{
    protected $table = 'usr';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'email', 'pass', 'Nombre', 'ap_Alumno', 'am_Alumno',
        'estatus', 'nivel', 'foto'
    ];

    public function verificarLogin($usuario, $password)
    {
        $user = $this->where('email', $usuario)->first();
        if (!$user) {
            return false;
        }

        $stored   = (string) ($user['pass'] ?? '');
        $password = (string) $password;

        // ¿La contraseña guardada ya es un hash seguro (bcrypt/argon)?
        $esHash = (strlen($stored) === 60 && str_starts_with($stored, '$2y$'))
               || str_starts_with($stored, '$argon');

        if ($esHash) {
            // Login normal con hash
            if (password_verify($password, $stored)) {
                return $user;
            }
            return false;
        }

        // MIGRACIÓN TRANSPARENTE: contraseña vieja en texto plano.
        // Si coincide, deja entrar y la re-guarda ya cifrada (no rompe a nadie).
        if ($stored !== '' && hash_equals($stored, $password)) {
            $this->update($user['id'], ['pass' => password_hash($password, PASSWORD_DEFAULT)]);
            return $user;
        }

        return false;
    }
}
