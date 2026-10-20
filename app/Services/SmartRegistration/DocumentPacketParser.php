<?php
namespace App\Services\SmartRegistration;

/** Logical document boundaries retain the original file and page numbers. */
class DocumentPacketParser
{
 public function parse(array $pages,string $defaultType):array {
  return app(OcrSandboxDocumentPacketParser::class)->parse($pages,$defaultType);
 }
}
