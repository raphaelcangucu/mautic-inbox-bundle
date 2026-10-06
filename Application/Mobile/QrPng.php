<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

/** Lossless image of the provider's QR matrix; no external renderer or image URL. */
final class QrPng
{
    public static function encode(array $matrix): string
    {
        $size=count($matrix);
        if ($size<21 || $size>97) { throw new \DomainException('Invalid QR matrix.'); }
        $scale=8;$quiet=4;$width=($size+2*$quiet)*$scale;$raw='';
        foreach ($matrix as $row) {
            if (!is_array($row) || count($row)!==$size || count(array_filter($row,'is_bool'))!==$size) { throw new \DomainException('Invalid QR matrix.'); }
        }
        for($y=0;$y<$width;$y++){
            $row="\x00";$my=intdiv($y,$scale)-$quiet;
            for($x=0;$x<$width;$x++){
                $mx=intdiv($x,$scale)-$quiet;
                $dark=$mx>=0 && $my>=0 && $mx<$size && $my<$size && $matrix[$my][$mx];
                $row.=$dark?"\x00":"\xff";
            }
            $raw.=$row;
        }
        $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$width,8,0,0,0,0)).$chunk('IDAT',gzcompress($raw,9)).$chunk('IEND','');
    }
}
