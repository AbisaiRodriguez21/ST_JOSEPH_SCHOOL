<?php namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\TextoBusqueda;

class CambioGradoModel extends Model
{
    protected $table      = 'usr';
    protected $primaryKey = 'id';
    protected $allowedFields = ['estatus', 'activo', 'grado']; 

    
    public function getAlumnos($busqueda = null, $porPagina = 20)
    {
        // Se calcula ANTES de armar el builder del modelo (usa su propia consulta)
        $palabras = TextoBusqueda::palabras($busqueda);
        $idsCoinciden = $palabras ? $this->idsQueCoinciden($palabras) : null;

        $builder = $this->select('usr.*, grados.nombreGrado, estatus_usr.nombre as nombre_estatus')
                        ->join('grados', 'usr.grado = grados.Id_grado', 'left')
                        ->join('estatus_usr', 'usr.estatus = estatus_usr.Id', 'left')
                        ->where('usr.nivel', 7)
                        ->where('usr.activo', 1);

        if ($idsCoinciden !== null) {
            // [0] = ningún resultado (whereIn con arreglo vacío no es válido)
            $builder->whereIn('usr.id', $idsCoinciden ?: [0]);
        }

        $builder->orderBy('usr.ap_Alumno', 'ASC')
                ->orderBy('usr.am_Alumno', 'ASC')
                ->orderBy('usr.Nombre', 'ASC');
        return $this->paginate($porPagina);
    }

    /**
     * Búsqueda tolerante: sin importar acentos, mayúsculas, comas, espacios ni el
     * orden de las palabras ("perez juan" = "Juan Pérez"). Cada palabra debe aparecer
     * en el nombre, apellidos, correo o matrícula.
     */
    private function idsQueCoinciden(array $palabras): array
    {
        $alumnos = $this->db->table('usr')
                            ->select('id, Nombre, ap_Alumno, am_Alumno, email, matricula')
                            ->where('nivel', 7)
                            ->where('activo', 1)
                            ->get()->getResultArray();

        $ids = [];
        foreach ($alumnos as $a) {
            $texto = TextoBusqueda::normalizar(
                "{$a['Nombre']} {$a['ap_Alumno']} {$a['am_Alumno']} {$a['email']} {$a['matricula']}"
            );
            if (TextoBusqueda::coincide($palabras, $texto)) {
                $ids[] = (int) $a['id'];
            }
        }

        return $ids;
    }

    public function getAlumnoDetalle($id)
    {
        return $this->select('usr.id, usr.grado, usr.email, usr.Nombre, usr.ap_Alumno, usr.am_Alumno, grados.nombreGrado')
                    ->join('grados', 'usr.grado = grados.Id_grado', 'left')
                    ->where('usr.id', $id)
                    ->first(); 
    }

    public function getListaGrados()
    {
        $db = \Config\Database::connect();
        return $db->table('grados')->orderBy('id_grado', 'ASC')->get()->getResultArray();
    }

    public function bajaAlumno($id)
    {
        return $this->update($id, ['estatus' => 2]);
    }

    // =======================================================
    // NUEVAS FUNCIONES DE LÓGICA DE NEGOCIO
    // =======================================================

    // 1. Obtener el ciclo activo real (Reemplaza a la variable mágica $cicloActivo)
    public function getCicloActivo()
    {
        $db = \Config\Database::connect();
        $row = $db->table('mesycicloactivo')->where('id', 1)->get()->getRow();
        
        // Si existe devuelve el id_ciclo (11), si no, devuelve 11 por defecto para no fallar
        return ($row) ? $row->id_ciclo : 11; 
    }

    // 2. Activación y Pago 
    public function activarConPago($idAlumno, $idNuevoGrado, $datosPago, $idCiclo)
    {
        $db = \Config\Database::connect();
        
        try {
            // A. FOLIO (MAX + 1)
            $query = $db->query("SELECT MAX(num_folio) as max_folio FROM folio");
            $row = $query->getRow();
            $siguienteNumFolio = ($row && $row->max_folio > 0) ? $row->max_folio + 1 : 1;

            if (!$db->table('folio')->insert(['num_folio' => $siguienteNumFolio])) {
                return "ERROR_FOLIO_INSERT: " . $db->error()['message'];
            }

            // B. PAGO  
            $datosInsertar = [
                'id_usr'        => $idAlumno,
                'cantidad'      => $datosPago['cantidad'],
                'recargos'      => 0,
                'total'         => $datosPago['cantidad'],
                'mes'           => $datosPago['mes'], 
                'fechaPago'     => $datosPago['fechaPago'],
                'qrp'           => $datosPago['qrp'],
                'concepto'      => $datosPago['concepto'],
                'modoPago'      => $datosPago['modoPago'],
                'nota'          => $datosPago['nota'],
                'validar_ficha' => 1, 
                'ficha'         => null, 
                'cilcoescolar'  => $idCiclo,  
                'id_folio'      => $siguienteNumFolio, 
                'fechaEnvio'    => date('Y-m-d H:i:s')
            ];

            if (!$db->table('pago')->insert($datosInsertar)) {
                return "ERROR_INSERT_PAGO: " . $db->error()['message'];
            }

            // C. ACTUALIZAR ALUMNO
            $datosAlumno = [
                'estatus' => 1, 
                'activo'  => 1,  
                'grado'   => intval($idNuevoGrado)
            ];

            if (!$this->update($idAlumno, $datosAlumno)) {
                return "ERROR_UPDATE_ALUMNO: " . $db->error()['message'];
            }

            return $siguienteNumFolio;

        } catch (\Exception $e) {
            return "EXCEPCION_PHP: " . $e->getMessage();
        }
    }

    // 3. Inicialización Académica 
    public function inicializarCalificaciones($idAlumno, $idGrado, $idCiclo)
    {
        $db = \Config\Database::connect();
        
        // A. Definir número de meses según el grado
        $meses = 0;
        
        // Primaria y Secundaria (según código viejo)
        if (in_array($idGrado, [22,23,24,25,26,27,28,29,30,34,35,36])) {
            $meses = 10;
        }
        // Bachillerato
        elseif (in_array($idGrado, [31,32,33])) {
            $meses = 6;
        }
        // Otros (Preescolar?)
        elseif (in_array($idGrado, [19,20,21])) {
            $meses = 3;
        }

        if ($meses == 0) return; // Si no cae en ningún rango, no hacemos nada

        // B. Obtener materias del nuevo grado
        $materias = $db->table('materia')
                       ->select('Id_materia')
                       ->where('id_grados', $idGrado)
                       ->get()
                       ->getResultArray();

        // C. Insertar calificaciones vacías
        if (!empty($materias)) {
            $dataBatch = [];
            foreach ($materias as $mat) {
                for ($i = 1; $i <= $meses; $i++) {
                    $dataBatch[] = [
                        'id_usr'       => $idAlumno,
                        'id_materia'   => $mat['Id_materia'],
                        'id_mes'       => $i,
                        'cicloEscolar' => $idCiclo, // <--- Usamos el 11
                        'id_grado'     => $idGrado,
                        'calificacion' => 0
                    ];
                }
            }
            
            // Insertar todo de un golpe  
            if (!empty($dataBatch)) {
                // Usamos ignore(true) para que si ya existen no de error
                $db->table('calificacion')->ignore(true)->insertBatch($dataBatch);
            }
        }
    }
}