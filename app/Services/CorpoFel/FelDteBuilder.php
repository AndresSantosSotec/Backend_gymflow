<?php

namespace App\Services\CorpoFel;

use App\Models\Receipt;
use Carbon\Carbon;

class FelDteBuilder
{
    /**
     * Construye el XML DTE (sin bloque de certificación; lo agrega Corpo Sistemas).
     */
    public function buildFromReceipt(Receipt $receipt, array $receptor): string
    {
        $cfg = config('billing.corpo_fel');
        $tz = config('app.timezone', 'America/Guatemala');
        $now = Carbon::now($tz);

        $total = round((float) $receipt->total, 6);
        $taxable = round($total / 1.12, 10);
        $tax = round($total - $taxable, 6);

        $description = $receipt->description
            ?: ucfirst(str_replace('_', ' ', (string) $receipt->payment_type));

        $receptorId = strtoupper(trim((string) ($receptor['id'] ?? 'CF')));
        $receptorName = $receptor['name'] ?? 'CONSUMIDOR FINAL';
        $receptorAddress = $receptor['address'] ?? 'CIUDAD';
        $receptorZip = $receptor['zip'] ?? '01001';
        $receptorMunicipality = $receptor['municipality'] ?? 'GUATEMALA';
        $receptorDepartment = $receptor['department'] ?? 'GUATEMALA';
        $receptorTipoEspecial = strtoupper((string) ($receptor['tipo_especial'] ?? ''));

        if ($receptorId === '' || $receptorId === 'C/F') {
            $receptorId = 'CF';
        }

        if ($receptorId === 'CF') {
            $receptorName = 'CONSUMIDOR FINAL';
            $receptorTipoEspecial = '';
        }

        $receptorTipoEspecialAttr = $receptorTipoEspecial !== ''
            ? ' TipoEspecial="' . $this->esc($receptorTipoEspecial) . '"'
            : '';

        $emisorNit = preg_replace('/\D/', '', (string) ($cfg['entity_nit'] ?? config('site.company_tax_id')));
        $emisorName = $cfg['emisor_nombre'] ?? config('site.company_name');
        $emisorCommercial = $cfg['emisor_nombre_comercial'] ?? $emisorName;
        $codigoEstablecimiento = $cfg['codigo_establecimiento'] ?? '1';
        $afiliacionIva = $cfg['afiliacion_iva'] ?? 'GEN';

        $direccionEmisor = $cfg['emisor_direccion'] ?? config('site.company_address', 'GUATEMALA');
        $cpEmisor = $cfg['emisor_codigo_postal'] ?? '01001';
        $muniEmisor = $cfg['emisor_municipio'] ?? 'GUATEMALA';
        $deptoEmisor = $cfg['emisor_departamento'] ?? 'GUATEMALA';
        $fraseTipo = $cfg['frase_tipo'] ?? '1';
        $fraseEscenario = $cfg['frase_escenario'] ?? '2';

        $totalLetras = FelNumberToWords::toQuetzales($total);

        $fechaEmision = $now->format('Y-m-d\TH:i:s');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<dte:GTDocumento xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" Version="0.1">'
            . '<dte:SAT ClaseDocumento="dte">'
            . '<dte:DTE ID="DatosCertificados">'
            . '<dte:DatosEmision ID="DatosEmision">'
            . '<dte:DatosGenerales Tipo="FACT" FechaHoraEmision="' . $this->esc($fechaEmision) . '" CodigoMoneda="GTQ"/>'
            . '<dte:Emisor NITEmisor="' . $this->esc($emisorNit) . '" NombreEmisor="' . $this->esc($emisorName) . '" '
            . 'CodigoEstablecimiento="' . $this->esc($codigoEstablecimiento) . '" NombreComercial="' . $this->esc($emisorCommercial) . '" AfiliacionIVA="' . $this->esc($afiliacionIva) . '">'
            . '<dte:DireccionEmisor>'
            . '<dte:Direccion>' . $this->esc($direccionEmisor) . '</dte:Direccion>'
            . '<dte:CodigoPostal>' . $this->esc($cpEmisor) . '</dte:CodigoPostal>'
            . '<dte:Municipio>' . $this->esc($muniEmisor) . '</dte:Municipio>'
            . '<dte:Departamento>' . $this->esc($deptoEmisor) . '</dte:Departamento>'
            . '<dte:Pais>GT</dte:Pais>'
            . '</dte:DireccionEmisor>'
            . '</dte:Emisor>'
            . '<dte:Receptor IDReceptor="' . $this->esc($receptorId) . '"' . $receptorTipoEspecialAttr . ' NombreReceptor="' . $this->esc($receptorName) . '">'
            . '<dte:DireccionReceptor>'
            . '<dte:Direccion>' . $this->esc($receptorAddress) . '</dte:Direccion>'
            . '<dte:CodigoPostal>' . $this->esc($receptorZip) . '</dte:CodigoPostal>'
            . '<dte:Municipio>' . $this->esc($receptorMunicipality) . '</dte:Municipio>'
            . '<dte:Departamento>' . $this->esc($receptorDepartment) . '</dte:Departamento>'
            . '<dte:Pais>GT</dte:Pais>'
            . '</dte:DireccionReceptor>'
            . '</dte:Receptor>'
            . '<dte:Frases>'
            . '<dte:Frase TipoFrase="' . $this->esc($fraseTipo) . '" CodigoEscenario="' . $this->esc($fraseEscenario) . '"/>'
            . '</dte:Frases>'
            . '<dte:Items>'
            . '<dte:Item NumeroLinea="1" BienOServicio="S">'
            . '<dte:Cantidad>1.0000000000</dte:Cantidad>'
            . '<dte:UnidadMedida>UNI</dte:UnidadMedida>'
            . '<dte:Descripcion>' . $this->esc($description) . '</dte:Descripcion>'
            . '<dte:PrecioUnitario>' . $this->fmt($total) . '</dte:PrecioUnitario>'
            . '<dte:Precio>' . $this->fmt($total) . '</dte:Precio>'
            . '<dte:Descuento>0</dte:Descuento>'
            . '<dte:Impuestos>'
            . '<dte:Impuesto>'
            . '<dte:NombreCorto>IVA</dte:NombreCorto>'
            . '<dte:CodigoUnidadGravable>1</dte:CodigoUnidadGravable>'
            . '<dte:MontoGravable>' . $this->fmt($taxable, 10) . '</dte:MontoGravable>'
            . '<dte:MontoImpuesto>' . $this->fmt($tax) . '</dte:MontoImpuesto>'
            . '</dte:Impuesto>'
            . '</dte:Impuestos>'
            . '<dte:Total>' . $this->fmt($total) . '</dte:Total>'
            . '</dte:Item>'
            . '</dte:Items>'
            . '<dte:Totales>'
            . '<dte:TotalImpuestos>'
            . '<dte:TotalImpuesto NombreCorto="IVA" TotalMontoImpuesto="' . $this->fmt($tax) . '"/>'
            . '</dte:TotalImpuestos>'
            . '<dte:GranTotal>' . $this->fmt($total) . '</dte:GranTotal>'
            . '</dte:Totales>'
            . '</dte:DatosEmision>'
            . '</dte:DTE>'
            . '<dte:Adenda>'
            . '<Adicionales xmlns="Schema-totalletras">'
            . '<TotalEnLetras>' . $this->esc($totalLetras) . '</TotalEnLetras>'
            . '</Adicionales>'
            . '</dte:Adenda>'
            . '</dte:SAT>'
            . '</dte:GTDocumento>';
    }

    /**
     * XML de anulación FEL (VOID_DOCUMENT).
     */
    public function buildVoidXml(
        string $uuid,
        string $receptorId,
        string $emissionDate,
        string $motivo = 'Anulacion solicitada',
    ): string {
        $cfg = config('billing.corpo_fel');
        $emisorNit = preg_replace('/\D/', '', (string) ($cfg['entity_nit'] ?? ''));
        $fechaAnulacion = self::formatFelDateTime(now('America/Guatemala'));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<dte:GTAnulacionDocumento xmlns:dte="http://www.sat.gob.gt/dte/fel/0.1.0" Version="0.1">'
            . '<dte:SAT><dte:AnulacionDTE ID="DatosCertificados">'
            . '<dte:DatosGenerales ID="DatosAnulacion"'
            . ' FechaEmisionDocumentoAnular="' . $this->esc($emissionDate) . '"'
            . ' FechaHoraAnulacion="' . $this->esc($fechaAnulacion) . '"'
            . ' IDReceptor="' . $this->esc($receptorId) . '"'
            . ' MotivoAnulacion="' . $this->esc($motivo) . '"'
            . ' NITEmisor="' . $this->esc($emisorNit) . '"'
            . ' NumeroDocumentoAAnular="' . $this->esc(strtoupper($uuid)) . '"/>'
            . '</dte:AnulacionDTE></dte:SAT></dte:GTAnulacionDocumento>';
    }

    public static function formatFelDateTime(\DateTimeInterface $date): string
    {
        $micro = (int) $date->format('u');
        $fraction = str_pad((string) (int) floor($micro / 100), 7, '0', STR_PAD_LEFT);

        return $date->format('Y-m-d\TH:i:s') . '.' . $fraction . $date->format('P');
    }

    public static function extractEmissionDateFromCertifyResponse(array $parsed): ?string
    {
        $decoded = $parsed['data1_decoded'] ?? null;
        if (is_string($decoded) && preg_match('/FechaHoraEmision="([^"]+)"/', $decoded, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function extractEmissionDateFromInfoResponse(array $parsed): ?string
    {
        $decoded = $parsed['data1_decoded'] ?? null;
        if (is_string($decoded) && preg_match('/"Fecha_de_emision"\s*:\s*"([^"]+)"/', $decoded, $m)) {
            return $m[1];
        }

        return null;
    }

    public function applyIvaToReceipt(Receipt $receipt): Receipt
    {
        $total = round((float) $receipt->total, 2);
        $subtotal = round($total / 1.12, 2);
        $tax = round($total - $subtotal, 2);

        $receipt->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ]);

        return $receipt->fresh();
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function fmt(float $value, int $decimals = 6): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
