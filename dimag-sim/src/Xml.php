<?php

// #ddev-generated (DDEV add-on: "ddev add-on get" may overwrite this copy)

declare(strict_types=1);

namespace DimagSim;

/** XML DIMAG writes: SOAP responses, exported control files, metadata files. */
final class Xml
{
    public const NS = 'http://dimag.la-bw.de/WebService.wsdl';

    /** canonical response element names [recorded]: changeStatusCode answers as changeStatuscode */
    public const RESPONSE_NAMES = [
        'getstatus' => 'getStatus',
        'getprefixlist' => 'getPrefixList',
        'importdoc' => 'importDoc',
        'exportdoc' => 'exportDoc',
        'changestatuscode' => 'changeStatuscode',
        'moveobject' => 'moveObject',
        'getaidforkeyvalue' => 'getAIDforKeyValue',
        'getobjectschangedsince' => 'getObjectsChangedSince',
    ];

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** literal parts in the order given, no xsi:type [recorded] */
    public static function response(string $operation, array $fields): string
    {
        $name = self::RESPONSE_NAMES[strtolower($operation)] ?? $operation;
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= "<$k>" . self::esc((string) $v) . "</$k>";
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="' . self::NS . '">'
            . "<SOAP-ENV:Body><ns1:{$name}Response>$body</ns1:{$name}Response></SOAP-ENV:Body></SOAP-ENV:Envelope>\n";
    }

    /** a SOAP fault as PHP's SoapServer sends it */
    public static function fault(string $code, string $string): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body><SOAP-ENV:Fault>'
            . '<faultcode>' . self::esc($code) . '</faultcode><faultstring>' . self::esc($string) . '</faultstring>'
            . "</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>\n";
    }

    /**
     * verz_obj.xml of an export [recorded layout]: tab indentation, fields
     * sorted by name, children after the fields, two blank lines after the
     * root element and three before its end. (withStruct exports differ in
     * layout; the simulator writes them like the others.)
     *
     * @param array{object:array, children:array} $tree
     */
    public static function exportControlFile(array $tree, callable $fields): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- DIMAG loadxml -->\n<verzeichnungseinheit>\n\n\n"
            . self::exportNode($tree, $fields, 1)
            . "\n\n\n</verzeichnungseinheit>";   // [recorded] no newline at the end
    }

    private static function exportNode(array $node, callable $fields, int $depth): string
    {
        $pad = str_repeat("\t", $depth);
        $xml = "$pad<verz-obj>\n";
        foreach ($fields($node['object']) as $k => $v) {
            $xml .= "$pad\t<$k>" . self::esc((string) $v) . "</$k>\n";
        }
        foreach ($node['children'] as $child) {
            $xml .= self::exportNode($child, $fields, $depth + 1);
        }
        return $xml . "$pad</verz-obj>\n";
    }

    /** fields that go to <allgemeine> in {aid}.xml [recorded for F]; the rest goes to <spezifische> */
    private const GENERAL = ['aenderungs-datum', 'aid', 'alternate-id', 'datei-format', 'dateiname', 'ersteller',
        'erstellungs-datum', 'geaendert-durch', 'metadaten-version', 'pid', 'status-code', 'titel', 'typ', 'versions-nummer'];

    /** {aid}.xml of an export with data [recorded layout for F] */
    public static function metadataFile(array $fields): string
    {
        unset($fields['sftp-dateiname']);
        $general = array_intersect_key($fields, array_flip(self::GENERAL));
        $specific = array_diff_key($fields, $general);
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- DIMAG metadata -->\n<metadaten>\n  <allgemeine>\n";
        foreach ($general as $k => $v) {
            $xml .= "    <$k>" . self::esc((string) $v) . "</$k>\n";
        }
        $xml .= "  </allgemeine>\n  <spezifische>\n";
        foreach ($specific as $k => $v) {
            $xml .= "    <$k>" . self::esc((string) $v) . "</$k>\n";
        }
        return $xml . "  </spezifische>\n</metadaten>\n";
    }
}
