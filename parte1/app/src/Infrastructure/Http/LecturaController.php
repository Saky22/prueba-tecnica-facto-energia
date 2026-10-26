<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\RegistrarLectura\RegistrarLecturaCommand;
use App\Application\RegistrarLectura\RegistrarLecturaHandler;
use App\Domain\Contrato\Exception\ContratoBajaException;
use App\Domain\Contrato\Exception\CupsNoEncontradoException;
use App\Domain\Contrato\Exception\FechaFueraVigenciaException;
use App\Domain\Lectura\Exception\FechaFuturaException;
use App\Domain\Lectura\Exception\LecturaConflictivaException;
use InvalidArgumentException;
use Throwable;
use Yii;
use yii\web\Controller;
use yii\web\Response;

class LecturaController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionCreate(): Response
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $raw = Yii::$app->request->getRawBody();
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            Yii::$app->response->statusCode = 400;
            return $this->asJson([
                'error'  => 'VALIDACION',
                'campos' => ['body' => 'El cuerpo de la petición debe ser un objeto JSON válido.'],
            ]);
        }

        $errors = $this->validateRequest($data);
        if (!empty($errors)) {
            Yii::$app->response->statusCode = 400;
            return $this->asJson(['error' => 'VALIDACION', 'campos' => $errors]);
        }

        try {
            /** @var RegistrarLecturaHandler $handler */
            $handler = Yii::$app->lecturaHandler;

            $command = new RegistrarLecturaCommand(
                cups: $data['cups'],
                fechaLectura: $data['fecha_lectura'],
                lecturaKwh: (int) $data['lectura_kwh'],
                tipo: $data['tipo'],
            );

            $response = $handler->handle($command);

            $statusCode = $response->duplicado ? 200 : 201;
            Yii::$app->response->statusCode = $statusCode;

            return $this->asJson($response->toArray());

        } catch (CupsNoEncontradoException $e) {
            Yii::$app->response->statusCode = 422;
            return $this->asJson(['error' => 'CUPS_NO_ENCONTRADO', 'mensaje' => $e->getMessage()]);

        } catch (ContratoBajaException $e) {
            Yii::$app->response->statusCode = 422;
            return $this->asJson(['error' => 'CONTRATO_BAJA', 'mensaje' => $e->getMessage()]);

        } catch (FechaFueraVigenciaException $e) {
            Yii::$app->response->statusCode = 422;
            return $this->asJson(['error' => 'FECHA_FUERA_VIGENCIA', 'mensaje' => $e->getMessage()]);

        } catch (FechaFuturaException $e) {
            Yii::$app->response->statusCode = 422;
            return $this->asJson(['error' => 'FECHA_FUTURA', 'mensaje' => $e->getMessage()]);

        } catch (LecturaConflictivaException $e) {
            Yii::$app->response->statusCode = 409;
            return $this->asJson([
                'error'                => 'LECTURA_CONFLICTIVA',
                'mensaje'              => $e->getMessage(),
                'lectura_existente_id' => $e->lecturaExistenteId,
                'lectura_kwh_existente'=> $e->kwhExistente,
            ]);

        } catch (InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 400;
            return $this->asJson(['error' => 'VALIDACION', 'campos' => ['general' => $e->getMessage()]]);

        } catch (Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
            Yii::$app->response->statusCode = 500;
            return $this->asJson(['error' => 'ERROR_INTERNO', 'mensaje' => 'Error al procesar la solicitud.']);
        }
    }

    /**
     * @return array<string, string> campo => mensaje; vacío si es válido
     */
    private function validateRequest(array $data): array
    {
        $errors = [];

        if (empty($data['cups'])) {
            $errors['cups'] = 'El campo cups es obligatorio.';
        } elseif (!is_string($data['cups'])) {
            $errors['cups'] = 'El campo cups debe ser una cadena de texto.';
        } elseif (!preg_match('/^ES[A-Z0-9]{18,20}$/i', $data['cups'])) {
            $errors['cups'] = 'El CUPS no tiene un formato válido (debe comenzar por ES y tener entre 20 y 22 caracteres).';
        }

        if (empty($data['fecha_lectura'])) {
            $errors['fecha_lectura'] = 'El campo fecha_lectura es obligatorio.';
        } elseif (!is_string($data['fecha_lectura'])) {
            $errors['fecha_lectura'] = 'El campo fecha_lectura debe ser una cadena de texto en formato ISO 8601 (YYYY-MM-DD).';
        } elseif (!\DateTimeImmutable::createFromFormat('Y-m-d', $data['fecha_lectura'])) {
            $errors['fecha_lectura'] = 'El campo fecha_lectura debe tener el formato YYYY-MM-DD.';
        }

        if (!isset($data['lectura_kwh'])) {
            $errors['lectura_kwh'] = 'El campo lectura_kwh es obligatorio.';
        } elseif (!is_int($data['lectura_kwh']) && !ctype_digit((string) $data['lectura_kwh'])) {
            $errors['lectura_kwh'] = 'El campo lectura_kwh debe ser un entero.';
        } elseif ((int) $data['lectura_kwh'] < 0) {
            $errors['lectura_kwh'] = 'Debe ser un entero no negativo.';
        }

        if (empty($data['tipo'])) {
            $errors['tipo'] = 'El campo tipo es obligatorio.';
        } elseif (!in_array($data['tipo'], ['real', 'estimada'], true)) {
            $errors['tipo'] = 'El campo tipo debe ser "real" o "estimada".';
        }

        return $errors;
    }
}
