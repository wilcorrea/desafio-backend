<?php

namespace App\Http\Controllers;

use App\Jobs\SendNotificationJob;
use App\Models\Transaction;
use App\Models\User;
use DB;
use Illuminate\Http\Request;
use Http;

class TransactionController extends Controller
{
    public function transactMoney(Request $request)
    {
        // Valida os dados da requisição
        $request->validate([
            'payer' => ['required', 'uuid'],
            'payee' => ['required', 'uuid'],
            'value' => ['required', 'int', 'min:1'],
            'type' => ['required', 'in:"c","d","p"'],
            'description' => ['required', 'string', 'max:255'],
        ]);
        // return response()->json($request);

        $payer = User::where('id', $request->payer)->first();
        $payee = User::where('id', $request->payee)->first();

        if (is_null($payer)) {
            return response()->json(['error' => 'Pagador não encontrado!'], 404);
        }

        if (is_null($payee)) {
            return response()->json(['error' => 'Beneficiario não encontrado!'], 404);
        }
        
        // return response()->json([$payer, $payee]);
        // Verifica se o usuário que está enviando a transferência é um usuário comum
        if ($payer->type === 'store') {
            // Lança uma exceção ou retorna uma mensagem de erro
            return response()->json(['error' => 'Lojistas não podem enviar dinheiro para outros usuários.'], 403);
        }

        // Verifica se o usuário que está enviando a transferência tem saldo suficiente
        if ($payer->balance < $request->value) {
            // Retorna uma mensagem de erro
            return response()->json(['error' => 'Você não tem saldo suficiente para realizar essa transferência.'], 400);
        }

        // Consulta o serviço autorizador externo
        try {
            $response = Http::get('https://run.mocky.io/v3/5794d450-d2e2-4412-8131-73d0293ac1cc');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Ocorreu um erro ao consultar o serviço autorizador externo.'], 500);
        }

        // Verifica se a consulta foi bem-sucedida
        if ($response->status() != 200) {
            // Retorna uma mensagem de erro
            return response()->json(['error' => 'Ocorreu um erro ao consultar o serviço autorizador externo.'], 500);
        }

        // Verifica se a transferência foi autorizada
        $authorization = $response->json();
        if ($authorization['message'] != 'Autorizado') {
            // Retorna uma mensagem de erro
            return response()->json(['error' => 'A transferência não foi autorizada pelo serviço autorizador externo.'], 403);
        }

        try {
            //inicia a transação
            DB::beginTransaction();

            // Realiza a transferência de dinheiro
            $transaction = Transaction::create([
                'payer' => $payer->id,
                'payee' => $payee->id,
                'value' => $request->value,
                'type' => $request->type,
                'description' => $request->description,
            ]);

            // Atualiza os saldos dos usuários envolvidos na transferência
            $payer->decrement('balance', $request->value);
            $payee->increment('balance', $request->value);

            // salva as alterações no banco de dados
            DB::commit();

            SendNotificationJob::dispatch($payee, $transaction);
            // $payee->notify((new PaymentReceived($transaction))->delay(now()->addMinutes(1)));

            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            // Se ocorrer um erro, desfaz as atualizações
            DB::rollBack();

            return response()->json(['error' => 'Ocorreu um erro ao processar a transação.'], 500);
        }
    }
}