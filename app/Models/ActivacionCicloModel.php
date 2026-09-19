<?php namespace App\Models;

use CodeIgniter\Model;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * ActivacionCicloModel
 *
 * Versión MASIVA del proceso de la carpeta "ciclo_alumnos" (recibe_excel.php).
 * El admin sube el archivo CRUDO de matrículas (agrupadas por sección de grado);
 * el módulo:
 *   1. Extrae las matrículas y su grado (por la sección) -> resuelve matrícula a id_usr.
 *   2. Hace EXACTAMENTE el mismo update que el sistema viejo, por cada alumno:
 *        - UPDATE usr (generacionactiva, activo=1, estatus=1, grado, pass reset)
 *        - INSERT calificacion  (por materia × mes)
 *        - INSERT calificacion_observaciones (momentos + general)
 */
class ActivacionCicloModel extends Model
{
    protected $table      = 'usr';
    protected $primaryKey = 'id';

    /** Sección del archivo (MAYÚSCULAS) -> id_grado. */
    private $mapaGrados = [
        'MATERNAL'      => 17,
        'PREESCOLAR 1'  => 19, '1 KINDER' => 19,
        'PREESCOLAR 2'  => 20, '2 KINDER' => 20,
        'PREESCOLAR 3'  => 21, '3 KINDER' => 21,
        'PRIMARIA 1'    => 22, '1 PRIMARIA' => 22,
        'PRIMARIA 2'    => 23, '2 PRIMARIA' => 23,
        'PRIMARIA 3'    => 24, '3 PRIMARIA' => 24,
        'PRIMARIA 4'    => 25, '4 PRIMARIA' => 25,
        'PRIMARIA 5'    => 26, '5 PRIMARIA' => 26,
        'PRIMARIA 6'    => 27, '6 PRIMARIA' => 27,
        'SECUNDARIA 1 A' => 28, 'SECUNDARIA 2 A' => 29, 'SECUNDARIA 3 A' => 30,
        'SECUNDARIA 1 B' => 34, 'SECUNDARIA 2 B' => 35, 'SECUNDARIA 3 B' => 36,
        // Bachillerato = Prepa. En el archivo suele venir como "PREPA SJS N".
        'BACHILLERATO 1' => 31, 'BACHILLERATO 2' => 32, 'BACHILLERATO 3' => 33,
        'PREPA SJS 1' => 31, 'PREPA SJS 2' => 32, 'PREPA SJS 3' => 33,
        'PREPA 1' => 31, 'PREPA 2' => 32, 'PREPA 3' => 33,
        'PREPARATORIA 1' => 31, 'PREPARATORIA 2' => 32, 'PREPARATORIA 3' => 33,
    ];

    /**
     * Secundaria por nivel -> id_grado (A y B). El archivo trae "SECUNDARIA N"
     * sin la letra; el grupo se toma del grado que el alumno ya tiene en la BD
     * (28-30 = A, 34-36 = B). Si no se puede saber, se usa A por defecto.
     */
    private $secA = [1 => 28, 2 => 29, 3 => 30];
    private $secB = [1 => 34, 2 => 35, 3 => 36];

    /** Catálogo de ciclos para el dropdown. */
    public function getCiclos()
    {
        return $this->db->table('cicloescolar')->orderBy('id_cicloEscolar', 'DESC')->get()->getResultArray();
    }

    /** Ciclo activo actual (para preseleccionar). */
    public function getCicloActivo()
    {
        $row = $this->db->table('mesycicloactivo')->where('id', 1)->get()->getRow();
        return $row ? (int) $row->id_ciclo : null;
    }

    /**
     * Ciclo que corresponde HOY segun el calendario escolar (no segun lo que
     * diga mesycicloactivo, que puede quedar desactualizado): a partir de
     * agosto es "esteAño-siguiente"; antes de agosto es "añoPasado-este".
     * Devuelve el id_cicloEscolar cuyo nombre coincide, o null si el catalogo
     * todavia no tiene ese ciclo dado de alta.
     */
    public function getCicloSugeridoPorFecha()
    {
        $hoy = new \DateTime();
        $anio = (int) $hoy->format('Y');
        $mes = (int) $hoy->format('n');

        $nombreEsperado = ($mes >= 8) ? "$anio-" . ($anio + 1) : ($anio - 1) . "-$anio";

        foreach ($this->getCiclos() as $c) {
            if (trim($c['nombreCicloEscolar']) === $nombreEsperado) {
                return (int) $c['id_cicloEscolar'];
            }
        }
        return null;
    }

    /** Mapa id_grado => nombreGrado (para mostrar nombres en la vista/informe). */
    public function getNombresGrados()
    {
        $mapa = [];
        foreach ($this->db->table('grados')->select('id_grado, nombreGrado')->get()->getResultArray() as $r) {
            $mapa[(int) $r['id_grado']] = $r['nombreGrado'];
        }
        return $mapa;
    }

    /**
     * Interpreta una fila de sección de grado.
     * @return array|null ['tipo'=>'fijo','id'=>int] | ['tipo'=>'sec','nivel'=>int] | ['tipo'=>'desconocido'] | null
     */
    /**
     * Limpia una celda: quita espacios normales Y "no-rompibles" (nbsp: en UTF-8
     * llega como \xC2\xA0, o como \xA0). Excel/WPS suele pegar estos espacios
     * invisibles al inicio de las matrículas, y PHP trim() no los quita.
     */
    private function limpiarCelda($v)
    {
        $v = str_replace(["\xC2\xA0", "\xA0"], ' ', (string) $v);
        return trim($v);
    }

    public function interpretarSeccion($texto)
    {
        $texto = str_replace(["\xC2\xA0", "\xA0"], ' ', (string) $texto);
        $clave = strtoupper(trim(preg_replace('/\s+/', ' ', $texto)));
        if ($clave === '' || in_array($clave, ['CURP', 'MATRICULA'], true)) {
            return null;
        }
        if (isset($this->mapaGrados[$clave])) {
            return ['tipo' => 'fijo', 'id' => $this->mapaGrados[$clave]];
        }
        if (preg_match('/^SECUNDARIA ([123])$/', $clave, $m) || preg_match('/^([123]) SECUNDARIA$/', $clave, $m)) {
            return ['tipo' => 'sec', 'nivel' => (int) $m[1]];
        }
        if (preg_match('/(MATERNAL|PREESCOLAR|KINDER|PRIMARIA|SECUNDARIA|BACHILLERATO|PREPA)/', $clave)) {
            return ['tipo' => 'desconocido'];
        }
        return null;
    }

    /**
     * Lee el archivo CRUDO (CSV exportado del Excel de matrículas) de forma stateful:
     * al topar una fila de sección fija el grado actual; a cada matrícula posterior
     * le asigna ese grado, hasta la siguiente sección.
     *
     * @return array{items: array, pendientes: int}
     */
    public function parsearArchivo($rutaTemporal)
    {
        $items = [];
        $pendientes = [];  // filas de alumnos SIN matrícula (se llenan a mano con datos personales)
        $seccion = null;
        $idGrado = null;   // grado directo mapeado
        $secNivel = null;  // nivel de secundaria (1/2/3) sin grupo

        $handle = fopen($rutaTemporal, 'r');
        if ($handle === false) {
            return ['items' => [], 'pendientes' => []];
        }

        while (($fila = fgetcsv($handle)) !== false) {
            $matricula = null;
            $seccionInfo = null;
            $celdasNoVacias = [];
            $esFilaEncabezado = false;

            // Un solo recorrido de la fila: busca matricula valida, encabezado
            // de seccion, y de paso junta las celdas con contenido (por si la
            // fila resulta ser un alumno sin matricula capturable).
            foreach ($fila as $celda) {
                $val = $this->limpiarCelda($celda);
                if ($val === '') {
                    continue;
                }
                $celdasNoVacias[] = $val;

                if (in_array(strtoupper($val), ['CURP', 'NOMBRE COMPLETO ALUMNO', 'MATRICULA'], true)) {
                    $esFilaEncabezado = true; // fila de encabezados de columnas, no es alumno
                }
                if ($matricula === null && preg_match('/^\d{4}[Aa]\d{3,6}$/', $val)) {
                    $matricula = strtoupper($val);
                }
                if ($seccionInfo === null) {
                    $info = $this->interpretarSeccion($celda);
                    if ($info !== null) {
                        $seccionInfo = ['texto' => $val, 'info' => $info];
                    }
                }
            }

            if ($esFilaEncabezado) {
                continue;
            }

            if ($matricula !== null) {
                $items[] = [
                    'matricula' => $matricula,
                    'seccion'   => $seccion,
                    'id_grado'  => $idGrado,
                    'sec_nivel' => $secNivel,
                ];
                continue;
            }

            // ¿Fila de sección de grado?
            if ($seccionInfo !== null) {
                $seccion = $seccionInfo['texto'];
                $info = $seccionInfo['info'];
                if ($info['tipo'] === 'fijo') {
                    $idGrado = $info['id'];
                    $secNivel = null;
                } elseif ($info['tipo'] === 'sec') {
                    $idGrado = null;
                    $secNivel = $info['nivel'];
                } else {
                    $idGrado = null;
                    $secNivel = null;
                }
                continue;
            }

            // Ni matricula ni encabezado de sección: si la fila tiene contenido
            // real, es un alumno SIN matrícula capturable -- diga o no la celda
            // "PENDIENTE" literalmente (p. ej. cuando el campo simplemente se
            // dejó en blanco al capturar el Excel de matrículas).
            if (count($celdasNoVacias) >= 2) {
                $datos = [];
                foreach ($celdasNoVacias as $v) {
                    if (strtoupper($v) === 'PENDIENTE') continue;
                    if (preg_match('/^\d{1,2}$/', $v)) continue; // saltar índice/año/mes/día cortos
                    if (preg_match('/^(N\.I\.|SIN CURP)$/i', $v)) continue;
                    $datos[] = $v;
                }
                if (!empty($datos)) {
                    $pendientes[] = [
                        'seccion' => $seccion,
                        'datos'   => implode(' | ', $datos),
                    ];
                }
            }
        }
        fclose($handle);

        return ['items' => $items, 'pendientes' => $pendientes];
    }

    /** Cuenta cuántas filas PENDIENTE (sin matrícula) trae el archivo. */
    public function contarPendientes(array $parseado)
    {
        return count($parseado['pendientes'] ?? []);
    }

    /**
     * Lee las listas de grupos reales que manda la escuela (.xlsx, una hoja por
     * grupo: "1A", "1B", "2A"... como las manda Vicente), para saber el grupo
     * VERDADERO de un alumno en vez de adivinarlo (ver $revueltoCounter en
     * clasificar()). También soporta la plantilla nueva de grupos (con celdas
     * "Nivel educativo"/"Grado" y columna "Grupo").
     *
     * Estas listas son autosuficientes: no necesitan venir acompañadas del CSV
     * general de matrículas para poder activar a secundaria (ver itemsDesdeGrupos()).
     *
     * @param string[] $rutasTemporales Rutas de los .xlsx subidos (uno o varios).
     * @return array matricula (MAYÚSCULAS) => ['letra' => 'A', 'nivel' => 1]
     */
    public function parsearGruposReales(array $rutasTemporales)
    {
        $mapa = [];

        foreach ($rutasTemporales as $ruta) {
            try {
                $spreadsheet = IOFactory::load($ruta);
            } catch (\Throwable $e) {
                continue; // archivo no legible: se ignora, no debe tumbar la previsualización
            }

            foreach ($spreadsheet->getAllSheets() as $ws) {
                $nombreHoja = trim($ws->getTitle());

                // Formato Vicente: hoja "1A", "2B", etc. -> numero+letra son el nivel y el grupo,
                // la matrícula se saca del correo institucional (columna G).
                if (preg_match('/^(\d+)\s*([A-Za-z])$/', $nombreHoja, $m)) {
                    $nivel = (int) $m[1];
                    $letra = strtoupper($m[2]);
                    $maxRow = $ws->getHighestRow();
                    for ($r = 1; $r <= $maxRow; $r++) {
                        $correo = trim((string) $ws->getCell("G$r")->getValue());
                        $mat = $this->matriculaDesdeCorreo($correo);
                        if ($mat !== '') {
                            $mapa[$mat] = ['letra' => $letra, 'nivel' => $nivel];
                        }
                    }
                    continue;
                }

                // Plantilla nueva "con grupos": B1=Nivel, B2=Grado, columna B=Matricula, G=Grupo.
                $etiqueta = trim((string) $ws->getCell('A4')->getValue());
                if (strcasecmp($etiqueta, 'No') === 0) {
                    $nivelTexto = strtoupper(trim((string) $ws->getCell('B1')->getValue()));
                    $nivel = (int) trim((string) $ws->getCell('B2')->getValue());
                    if ($nivelTexto === 'SECUNDARIA' && $nivel > 0) {
                        $maxRow = $ws->getHighestRow();
                        for ($r = 5; $r <= $maxRow; $r++) {
                            $mat = $this->limpiarCelda($ws->getCell("B$r")->getValue());
                            $letra = strtoupper(trim((string) $ws->getCell("G$r")->getValue()));
                            if ($mat !== '' && $letra !== '' && $letra !== 'UNICO') {
                                $mapa[strtoupper($mat)] = ['letra' => $letra, 'nivel' => $nivel];
                            }
                        }
                    }
                }
            }
        }

        return $mapa;
    }

    /**
     * Construye items ['matricula','seccion','id_grado'=>null,'sec_nivel'] a partir
     * de las listas de grupos, para las matrículas que NO vinieron ya en el CSV
     * general (p. ej. si el CSV excluye secundaria a propósito). Así las listas de
     * Vicente son suficientes por sí solas para activar secundaria, sin depender
     * de que el CSV general también incluya esas filas.
     *
     * @param array $gruposReales  Salida de parsearGruposReales().
     * @param array $matriculasYaCubiertas  Matrículas que ya vienen en $parseado['items'].
     */
    public function itemsDesdeGrupos(array $gruposReales, array $matriculasYaCubiertas)
    {
        $yaCubiertas = array_flip($matriculasYaCubiertas);
        $items = [];
        foreach ($gruposReales as $mat => $info) {
            if (isset($yaCubiertas[$mat])) {
                continue; // ya lo trae el CSV general; se resuelve como override en clasificar()
            }
            $items[] = [
                'matricula' => $mat,
                'seccion'   => "SECUNDARIA {$info['nivel']} (lista de grupos)",
                'id_grado'  => null,
                'sec_nivel' => (int) $info['nivel'],
            ];
        }
        return $items;
    }

    private function matriculaDesdeCorreo($correo)
    {
        $correo = str_replace(["\xC2\xA0", "\xA0"], '', (string) $correo);
        if (preg_match('/^([A-Za-z0-9]+)@/', trim($correo), $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }

    /**
     * Cruza matrículas contra la BD y las clasifica en:
     * listos (se activarán) y casos que se saltan (no encontradas / grado no reconocido / duplicadas).
     */
    public function clasificar(array $parseado, int $idCiclo, array $gruposReales = [])
    {
        $items = $parseado['items'];
        $pendientes = $parseado['pendientes'] ?? [];  // filas sin matrícula -> llenar a mano

        // Quitar duplicados DENTRO DEL ARCHIVO (misma matrícula en 2 secciones).
        // $vistas guarda la sección de la primera aparición para poder mostrar ambas.
        $vistas = $duplicadas = $unicos = [];
        foreach ($items as $it) {
            if (isset($vistas[$it['matricula']])) {
                $it['seccion_usada']     = $vistas[$it['matricula']]; // la que sí se tomó
                $it['seccion_repetida']  = $it['seccion'];            // la que se ignora
                $duplicadas[] = $it;
                continue;
            }
            $vistas[$it['matricula']] = $it['seccion'];
            $unicos[] = $it;
        }

        // Traer todas las matrículas de un golpe
        $matriculas = array_column($unicos, 'matricula');
        $enBD = [];
        if (!empty($matriculas)) {
            $rows = $this->db->table('usr')
                             ->select('id, matricula, grado, estatus, activo, generacionactiva, Nombre, ap_Alumno, am_Alumno')
                             ->whereIn('matricula', $matriculas)
                             ->get()->getResultArray();
            foreach ($rows as $r) {
                $enBD[$r['matricula']] = $r;
            }
        }
        $nombreDe = static fn($r) => trim(($r['ap_Alumno'] ?? '') . ' ' . ($r['am_Alumno'] ?? '') . ' ' . ($r['Nombre'] ?? '')) ?: '(sin nombre)';

        $listos = $noEncontradas = $gradoNoReconocido = $yaActivos = [];
        $revueltoCounter = 0; // para repartir balanceado A/B en secundaria sin grupo

        foreach ($unicos as $it) {
            $mat = $it['matricula'];
            if (!isset($enBD[$mat])) {
                $noEncontradas[] = $it;
                continue;
            }

            // CANDADO: si ya está ACTIVO en el ciclo destino, no se vuelve a procesar
            // (solo se activan los que están "en proceso"). Evita duplicar si se sube 2 veces.
            if ((int) $enBD[$mat]['activo'] === 1
                && (int) $enBD[$mat]['estatus'] === 1
                && (int) $enBD[$mat]['generacionactiva'] === $idCiclo) {
                $it['id_usr'] = (int) $enBD[$mat]['id'];
                $it['nombre'] = $nombreDe($enBD[$mat]);
                $yaActivos[]  = $it;
                continue;
            }

            $gradoActual = (int) $enBD[$mat]['grado'];
            $revuelto = false;
            $grupoReal = false;

            // Resolver grado destino
            if ($it['id_grado'] !== null) {
                $idGradoDestino = (int) $it['id_grado'];
            } elseif (($it['sec_nivel'] ?? null) !== null) {
                // Secundaria SIN grupo en el archivo: definir A/B
                $nivel = (int) $it['sec_nivel'];

                if (isset($gruposReales[$mat])) {
                    // Prioridad 1: grupo REAL de las listas de la escuela (Vicente).
                    // Manda sobre el grado anterior por si reacomodaron al alumno.
                    $idGradoDestino = ($gruposReales[$mat]['letra'] === 'A') ? $this->secA[$nivel] : $this->secB[$nivel];
                    $grupoReal = true;
                } elseif (in_array($gradoActual, $this->secA, true)) {
                    $idGradoDestino = $this->secA[$nivel];        // continúa en grupo A
                } elseif (in_array($gradoActual, $this->secB, true)) {
                    $idGradoDestino = $this->secB[$nivel];        // continúa en grupo B
                } else {
                    // No tiene grupo previo ni viene en las listas reales -> REVOLVER balanceado A/B.
                    // (Sí tienen matrícula, así que se activan; los manuales son los SIN matrícula.)
                    $idGradoDestino = ($revueltoCounter % 2 === 0) ? $this->secA[$nivel] : $this->secB[$nivel];
                    $revueltoCounter++;
                    $revuelto = true;
                }
            } else {
                $it['id_usr'] = (int) $enBD[$mat]['id'];
                $it['nombre'] = $nombreDe($enBD[$mat]);
                $gradoNoReconocido[] = $it;
                continue;
            }

            $listos[] = [
                'matricula'    => $mat,
                'id_usr'       => (int) $enBD[$mat]['id'],
                'nombre'       => $nombreDe($enBD[$mat]),
                'id_grado'     => $idGradoDestino,
                'seccion'      => $it['seccion'],
                'grado_actual' => $gradoActual,
                'revuelto'     => $revuelto,
                'grupo_real'   => $grupoReal,
            ];
        }

        return [
            'listos'            => $listos,
            'yaActivos'         => $yaActivos, // ya estaban activos en este ciclo (se saltan)
            'noEncontradas'     => $noEncontradas,
            'gradoNoReconocido' => $gradoNoReconocido,
            'duplicadas'        => $duplicadas,
            'pendientes'        => $pendientes, // lista de filas sin matrícula (manual)
        ];
    }

    /**
     * Aplica la activación masiva en una transacción.
     * Replica EXACTAMENTE el update de recibe_excel.php (usr + calificacion + observaciones).
     *
     * @return array{ok:bool, activados:int, boletas:int, msg:string}
     */
    public function activarLote(array $listos, int $idCiclo)
    {
        if (empty($listos)) {
            return ['ok' => false, 'activados' => 0, 'boletas' => 0, 'msg' => 'No hay alumnos para activar.'];
        }

        $this->db->transStart();
        $activados = 0;
        $boletas   = 0;

        // Contraseña default CIFRADA (se calcula una sola vez, no en cada alumno).
        $passDefault = password_hash('123456789', PASSWORD_DEFAULT);

        // Cachés por grado (no por alumno) para no repetir consultas pesadas.
        $materiasPorGrado = [];
        $existentesPorGrado = [];
        $conObservacionesPorGrado = [];
        $momentos = $this->db->table('meses_calificacion')->select('mes')->where('mes <=', 3)->orderBy('mes', 'ASC')->get()->getResultArray();

        foreach ($listos as $a) {
            $idUsr  = (int) $a['id_usr'];
            $idGrado = (int) $a['id_grado'];

            // 1. Activar alumno (igual que recibe_excel.php, pero con contraseña cifrada)
            $this->db->table('usr')->where('id', $idUsr)->update([
                'generacionactiva' => $idCiclo,
                'activo'           => 1,
                'estatus'          => 1,
                'grado'            => $idGrado,
                'pass'             => $passDefault,
            ]);
            $activados++;

            // 2. Calificaciones + 3. Observaciones. Las materias y el "ya existe"
            // se calculan UNA vez por grado (no por alumno): antes se repetian
            // esas mismas consultas cientos de veces, contra una tabla de ~1M
            // filas sin indice, y eso era lo que tronaba la carga por tiempo.
            if (!isset($materiasPorGrado[$idGrado])) {
                $materiasPorGrado[$idGrado] = $this->db->table('materia')
                    ->select('Id_materia')->where('id_grados', $idGrado)
                    ->get()->getResultArray();
            }
            $claveGradoCiclo = "$idGrado:$idCiclo";
            if (!isset($existentesPorGrado[$claveGradoCiclo])) {
                $existentesPorGrado[$claveGradoCiclo] = $this->cargarIdsConCalificaciones($idGrado, $idCiclo);
            }
            if (!isset($conObservacionesPorGrado[$claveGradoCiclo])) {
                $conObservacionesPorGrado[$claveGradoCiclo] = $this->cargarIdsConObservaciones($idGrado, $idCiclo);
            }

            $boletas += $this->generarBoletas(
                $idUsr,
                $idGrado,
                $idCiclo,
                $materiasPorGrado[$idGrado],
                isset($existentesPorGrado[$claveGradoCiclo][$idUsr]),
                isset($conObservacionesPorGrado[$claveGradoCiclo][$idUsr]),
                $momentos
            );
        }

        // Dejar el ciclo nuevo como ACTIVO en el sistema (las 3 configs de nivel),
        // para que las boletas y calificaciones ya usen este ciclo por default.
        $this->db->table('mesycicloactivo')->update(['id_ciclo' => $idCiclo]);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return ['ok' => false, 'activados' => 0, 'boletas' => 0, 'msg' => 'Error en la transacción; no se activó a nadie.'];
        }

        return [
            'ok'        => true,
            'activados' => $activados,
            'boletas'   => $boletas,
            'msg'       => "Se activaron $activados alumno(s), se generaron $boletas calificación(es) y el ciclo quedó como activo en el sistema.",
        ];
    }

    /** Set (id_usr => true) de alumnos que YA tienen calificaciones para ese grado+ciclo. */
    private function cargarIdsConCalificaciones(int $idGrado, int $idCiclo): array
    {
        $rows = $this->db->table('calificacion')
                         ->distinct()
                         ->select('id_usr')
                         ->where('id_grado', $idGrado)
                         ->where('cicloEscolar', $idCiclo)
                         ->get()->getResultArray();
        $set = [];
        foreach ($rows as $r) $set[(int) $r['id_usr']] = true;
        return $set;
    }

    /** Set (id_usr => true) de alumnos que YA tienen observaciones para ese grado+ciclo. */
    private function cargarIdsConObservaciones(int $idGrado, int $idCiclo): array
    {
        $rows = $this->db->table('calificacion_observaciones')
                         ->distinct()
                         ->select('id_usr')
                         ->where('id_grado', $idGrado)
                         ->where('cicloEscolar', $idCiclo)
                         ->get()->getResultArray();
        $set = [];
        foreach ($rows as $r) $set[(int) $r['id_usr']] = true;
        return $set;
    }

    /**
     * Genera calificaciones (materia × mes) + observaciones (momentos + general),
     * igual que recibe_excel.php. Idempotente: no duplica si ya existen.
     * $materias, $yaExisten, $yaTieneObs y $momentos se calculan UNA vez por
     * grado+ciclo en activarLote() y se reutilizan para todos sus alumnos.
     * @return int filas de calificación insertadas
     */
    private function generarBoletas($idUsr, $idGrado, $idCiclo, array $materias, bool $yaExisten, bool $yaTieneObs, array $momentos)
    {
        if ($yaExisten || empty($materias)) {
            return 0;
        }

        $meses = $this->mesesDelGrado($idGrado);
        if ($meses === 0) {
            return 0;
        }

        // --- Calificaciones (materia × mes) ---
        $lote = [];
        foreach ($materias as $m) {
            for ($i = 1; $i <= $meses; $i++) {
                $lote[] = [
                    'id_usr'       => $idUsr,
                    'id_materia'   => (int) $m['Id_materia'],
                    'id_mes'       => $i,
                    'cicloEscolar' => $idCiclo,
                    'id_grado'     => $idGrado,
                    'calificacion' => 0,
                ];
            }
        }
        if (!empty($lote)) {
            $this->db->table('calificacion')->ignore(true)->insertBatch($lote);
        }

        // --- Observaciones (una sola vez por alumno/grado/ciclo) ---
        if (!$yaTieneObs) {
            $obs = [];
            foreach ($momentos as $mo) {
                $obs[] = ['id_usr' => $idUsr, 'id_momento' => (int) $mo['mes'], 'id_grado' => $idGrado, 'cicloEscolar' => $idCiclo];
            }
            // Observación general (id_momento = 0)
            $obs[] = ['id_usr' => $idUsr, 'id_momento' => 0, 'id_grado' => $idGrado, 'cicloEscolar' => $idCiclo];
            if (!empty($obs)) {
                $this->db->table('calificacion_observaciones')->insertBatch($obs);
            }
        }

        return count($lote);
    }

    /**
     * Número de meses de calificación por grado.
     * Usa grados.meses_calificacion si está definido; si no, cae a los rangos históricos.
     */
    private function mesesDelGrado($idGrado)
    {
        $row = $this->db->table('grados')->select('meses_calificacion')->where('id_grado', $idGrado)->get()->getRow();
        if ($row && (int) $row->meses_calificacion > 0) {
            return (int) $row->meses_calificacion;
        }
        if (in_array($idGrado, [22,23,24,25,26,27,28,29,30,34,35,36], true)) return 10; // Primaria/Secundaria
        if (in_array($idGrado, [31,32,33], true)) return 6;                              // Bachillerato
        if (in_array($idGrado, [17,19,20,21], true)) return 3;                           // Maternal/Preescolar
        return 0;
    }
}
