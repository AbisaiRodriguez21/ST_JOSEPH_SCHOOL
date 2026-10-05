<?php namespace App\Models;

use CodeIgniter\Model;

class TitularModel extends Model
{
    protected $table      = 'usr';
    protected $primaryKey = 'id';

    // campos permitidos para tocar
    protected $allowedFields = ['Nombre', 'ap_Alumno', 'am_Alumno', 'email', 'pass', 'nivel', 'nivelT', 'activo', 'estatus'];

    /**
     * Trae todos los grados escolares disponibles 
     */
    public function getGrados()
    {
        $db = \Config\Database::connect();
        $query = $db->query("SELECT * FROM grados WHERE Id_grado >= 17 ORDER BY Id_grado ASC");
        return $query->getResultArray();
    }

    /**
     * Busca cualquier puesto ocupado por un usuario ACTIVO,
     * sin importar si es Nivel 2 (Director) o Nivel 9 (Titular).
     */
    public function getNivelesOcupados()
    {
        // '0' y '' como texto: comparar contra el número 0 excluía los nivelT
        // de texto (MySQL convierte 'todoprimaria' a 0).
        return $this->select('nivelT, email')
                    ->where('activo', 1)
                    ->where('nivelT IS NOT NULL')
                    ->where('nivelT !=', '0')
                    ->where('nivelT !=', '')
                    ->findAll();
    }
}