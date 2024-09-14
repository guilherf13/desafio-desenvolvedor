<?php
namespace App\Http\Controllers;

use App\Http\Requests\UploadFileRequest;
use App\Http\Requests\UploadRequest;
use Illuminate\Http\Request;
use App\Services\UploadService;

class UploadController extends Controller
{
    protected UploadService $uploadService;

    public function __construct(UploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    // Endpoint para upload de arquivo
    public function uploadFile(UploadFileRequest $request): \Illuminate\Http\JsonResponse
    {

        $result = $this->uploadService->uploadFile($request->file('file'));

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 400);
        }

        return response()->json(['message' => 'Upload bem-sucedido!', 'data' => $result['upload']], 201);
    }

    // Endpoint para histórico de uploads
    public function getUploadHistory(UploadRequest $request): \Illuminate\Http\JsonResponse
    {
        //Nome do arquivo é obrigatorio e data é opicional

        $filters = $request->only(['name', 'uploaded_at']);
        $uploads = $this->uploadService->getUploadHistory($filters);

        return response()->json($uploads);
    }

    // Endpoint para busca de conteúdo do arquivo
    public function searchFileContent(Request $request): \Illuminate\Http\JsonResponse
    {
        $filters = $request->only(['TckrSymb', 'RptDt']);
        $results = $this->uploadService->searchFileContent($filters);

        return response()->json($results);
    }

    public function createToken(): array|\Illuminate\Http\JsonResponse
    {
        // Recupera o usuário autenticado
        $user = \App\Models\User::find(1);

        // Verifica se o usuário está autenticado
        if (!$user) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        // Cria o token para o usuário autenticado
        $token = $user->createToken('teste');

        return ['token' => $token->plainTextToken];
    }
}

