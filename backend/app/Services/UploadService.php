<?php

namespace App\Services;

use App\Models\FileContent;
use App\Models\Upload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;


class UploadService
{
    public function uploadFile($file): array
    {
        Log::info("Upload started");
        try {
            $fileName = $file->getClientOriginalName();
            $fileHash = hash_file('sha256', $file->getRealPath());

            $path = $file->storeAs('uploads', $fileName, 'public');
            $fullPath = storage_path('app/public/' . $path);

            // Ler o arquivo CSV usando a função readCsv
            $items = self::readCsv($fullPath);

            // Formatar os valores das colunas do CSV
            $formattedItems = self::formatarValores($items);


            // Iniciar uma transação
            DB::beginTransaction();

            $array_itens = [];

            foreach ($formattedItems as $item) {
              //Inserir cada linha no banco de dados mapeando os campos do modelo FileContent
                $array_itens[] = [
                    'RptDt' => $item['RptDt'] ?? null,
                    'TckrSymb' => $item['TckrSymb'] ?? null,
                    'MktNm' => $item['MktNm'] ?? null,
                    'SctyCtgyNm' => $item['SctyCtgyNm'] ?? null,
                    'ISIN' => $item['ISIN'] ?? null,
                    'CrpnNm' => $item['CrpnNm']?? null,
               ];
            }

            $batchSize = 500;
            $chunks = array_chunk($array_itens, $batchSize);

            foreach ($chunks as $chunk) {
                FileContent::query()->insert($chunk);
            }

          //Salvar detalhes do upload no MongoDB
            $upload = Upload::create([
                'name' => $fileName,
                'hash' => $fileHash,
                'path' => $path,
                'uploaded_at' => now(),
            ]);

            DB::commit();

            return ['success' => true, 'upload' => $upload];
        }catch (\Exception $exception){
            DB::rollBack();
            return ['success' => false, 'message' => $exception->getMessage()];
        }

    }

    /**
     * Histórico de uploads com filtros de nome e data.
     */
    public function getUploadHistory($filters)
    {

        $query = Upload::query();

        if (isset($filters['name'])) {
            $query->where('name', $filters['name']);
        }

        if (isset($filters['uploaded_at'])) {
            $query->whereDate('uploaded_at', $filters['uploaded_at']);
        }

        // Cache para otimizar buscas
        return Cache::remember('upload-history', 600, function () use ($query) {
            return $query->get();
        });
    }

    public function searchFileContent(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator|array
    {
        $query = FileContent::query();

        // Verificar se algum filtro válido foi passado
        $hasValidFilter = false;

        // Aplicar filtros se existirem
        if (isset($filters['TckrSymb'])) {
            $query->where('TckrSymb', $filters['TckrSymb']);
            $hasValidFilter = true;
        }

        if (isset($filters['RptDt'])) {
            $query->where('RptDt', $filters['RptDt']);
            $hasValidFilter = true;
        }

        // Se nenhum filtro válido for encontrado, retornar mensagem de erro
        if (!$hasValidFilter) {
            return [
                'success' => false,
                'message' => 'Nenhum filtro válido fornecido. Por favor, forneça pelo menos um dos filtros: TckrSymb ou RptDt.'
            ];
        }

        // Retornar resultados paginados
        return $query->paginate(10);
    }

    public static function readCsv($path): array
    {
        // Detectar o separador (pode ser ',' ou ';')
        $separator = self::detectCSVSeparator($path);

        // Abrir o arquivo CSV para leitura
        $file = fopen($path, 'r');
        if ($file === false) {
            throw new \Exception("Não foi possível abrir o arquivo CSV.");
        }

        // Retira a primeira linha
        fgetcsv($file, null, $separator, '"');

        //Ler a segunda vez para inciar sem o caberçario
        $header = fgetcsv($file, null, $separator, '"');
        if ($header === false) {
            throw new \Exception("Não foi possível ler o cabeçalho do arquivo CSV.");
        }

        // Converter o cabeçalho para UTF-8
        $header = array_map(function($value) {
            return mb_convert_encoding($value, 'UTF-8', 'auto');
        }, $header);

        $rows = [];

        // Iterar pelas linhas subsequentes do arquivo
        while (($row = fgetcsv($file, null, $separator, '"')) !== false) {
            $rowData = [];

            // Adiciona os valores ao array $rowData com base nos cabeçalhos disponíveis
            foreach ($header as $index => $headerItem) {
                if (isset($row[$index])) {
                    $rowData[$headerItem] = $row[$index];
                } else {
                    $rowData[$headerItem] = null; // Adiciona o cabeçalho com valor null se não houver dado correspondente
                }
            }

            $rows[] = $rowData;
        }

        // Fechar o arquivo após a leitura
        fclose($file);

        return $rows;
    }

    public static function formatarValores($items): array
    {
        $newItems = [];

        foreach ($items as $key => $item) {
            foreach ($item as $k => $value) {
                // Remove caracteres indesejados das chaves e valores e converte para UTF-8
                $newKey = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', trim($k));
                $newValue = mb_convert_encoding(trim($value), 'UTF-8', 'auto');

                $newItems[$key][$newKey] = $newValue;
            }
        }

        return $newItems;
    }

    public static function detectCSVSeparator($path): string
    {
        // Abrir o arquivo CSV e ler a primeira linha
        $file = fopen($path, 'r');
        $line = fgets($file);
        fclose($file);

        // Verificar se a linha contém ';' ou ',' como separador
        if (strpos($line, ';') !== false) {
            return ';';
        }

        return ',';
    }



}
