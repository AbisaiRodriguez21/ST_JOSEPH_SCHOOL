<?php namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\TitularModel;

class AsignarTitulares extends BaseController
{
    // Directores (nivel 2): el nivelT limita qué grados ven (ver App\Libraries\AlcanceDirector)
    private const DIRECCIONES = [
        'todokinder'       => 'Director(a) de Kinder',
        'todoprimaria'     => 'Director(a) de Primaria',
        'todosecundaria'   => 'Director(a) de Secundaria',
        'todobachillerato' => 'Director(a) de Bachillerato',
    ];

    public function index()
    {
        if (!$this->_verificarPermisos()) {
            return redirect()->to('/dashboard')->with('error', 'No autorizado.');
        }

        $model = new TitularModel();

        $grados = $model->getGrados();

        // 1. Obtenemos la lista de ocupados
        $ocupadosRaw = $model->getNivelesOcupados();
        
        // 2. Mapa nivelT => correos de quien ocupa el puesto.
        // Llave en string para que la búsqueda en la vista sea exacta.
        $ocupados = [];
        foreach ($ocupadosRaw as $o) {
            $ocupados[(string) $o['nivelT']][] = $o['email'];
        }

        $data = [
            'grados'      => $grados,
            'ocupados'    => $ocupados,
            'direcciones' => self::DIRECCIONES
        ];

        return view('titulares/asignar', $data);
    }

    public function guardar()
    {
        if (!$this->_verificarPermisos()) {
            return redirect()->to('/dashboard');
        }

        $request = \Config\Services::request();
        $model = new TitularModel();

        

        // El puesto no debe estar ocupado ya por un usuario activo
        $ocupadosNivelT = array_map('strval', array_column($model->getNivelesOcupados(), 'nivelT'));
        if (in_array((string) $request->getPost('nivelT'), $ocupadosNivelT, true)) {
            return redirect()->back()->withInput()->with('error', 'Ese puesto ya tiene a alguien asignado.');
        }

        // Las opciones de Dirección se guardan como Director (nivel 2)
        $esDireccion = array_key_exists((string) $request->getPost('nivelT'), self::DIRECCIONES);

        // Preparamos los datos
        $data = [
            'Nombre'    => $request->getPost('nombre'),
            'ap_Alumno' => $request->getPost('paterno'),
            'am_Alumno' => $request->getPost('materno'),
            'email'     => $request->getPost('email'),
            'pass'      => password_hash($request->getPost('password'), PASSWORD_DEFAULT),
            'nivel'     => $esDireccion ? 2 : 9, // Director o Titular
            'nivelT'    => $request->getPost('nivelT'),
            'activo'    => 1,  
            'estatus'  => 1  
        ];

        if ($model->insert($data)) {
            $tipo = $esDireccion ? 'Director' : 'Titular';
            return redirect()->to('/asignar-titulares')->with('success', "$tipo registrado correctamente.");
        } else {
            return redirect()->back()->with('error', 'Error al guardar en la base de datos.');
        }
    }
}