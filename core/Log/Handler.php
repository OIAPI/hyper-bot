<?php
namespace Core\Log;

interface Handler {
    /** @param array{time:string,ts:float,level:string,channel:string,message:string,context:array} $record */
    public function handle(array $record): void;
}
