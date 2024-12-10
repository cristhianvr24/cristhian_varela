<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\RequestLogs;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    // Método que maneja el proceso de pago con el proveedor EasyMoney
    public function processEasyMoney(Request $request){
        try {
            // Validación de los datos recibidos en el request
            $validated = $request->validate([
                'amount' => 'required|integer|min:1',
                'currency' => 'required|string|min:3',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Si la validación falla, se retorna un error con los detalles de la validación
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 400);
        }
        
        try{
            // Realizamos una solicitud HTTP POST al endpoint de EasyMoney
            $response = Http::post('http://localhost:3000/process', [
                'amount' => $validated['amount'],
                'currency' => $validated['currency'],
            ]);

            // Si la respuesta del proveedor es fallida, se retorna un error
            if($response->failed()){
                return response()->json([
                    'error' => 'Failed to process payment',
                    'details' => $response->json(),
                ], 400);
            }

            // Iniciamos una transacción para guardar los datos en la base de datos
            \DB::transaction(function () use($validated, $response) {
                Transaction::create([
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'provider' => 'EasyMoney', 
                    'status' => $response->successful() ? 'success' : 'failed',
                    'transaction_id' => $response->json()['transaction_id'] ?? null,
                ]);
    
                // Se guarda un registro de la solicitud y respuesta en los log
                RequestLogs::create([
                    'provider' => 'EasyMoney', 
                    'endpoint' => 'http://localhost:3000/process', 
                    'payload' => json_encode($validated),
                    'response' => $response->successful() ? 'success' : 'failed',
                ]);
            });
        
            // Se retorna una respuesta de éxito con el mensaje correspondiente
            return response()->json([
                'message' => 'Payment processed successfully',
                'data' => 'Successfully',
            ]);

        }catch(\Exception $e){
            // Si ocurre alguna excepción, se retorna un error general con el mensaje de la excepción
            return response()->json([
                'error' => 'An unexpected error ocurred',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    // Método que maneja el proceso de pago con el proveedor SuperWalletz
    public function processSuperWalletz(Request $request)
    {
        try {
            // Validación de los datos recibidos en el request
            $validated = $request->validate([
                'amount' => 'required|numeric|min:1',
                'currency' => 'required|string|max:3',
                'callback_url' => 'required|url',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Si la validación falla, se retorna un error con los detalles de la validación
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 400);
        }
        try {
            // Realizamos una solicitud HTTP POST al endpoint de SuperWalletz
            $response = Http::post('http://localhost:3003/pay', [
                'amount' => $validated['amount'],
                'currency' => $validated['currency'],
                'callback_url' => $validated['callback_url'],
            ]);

            // Si la respuesta del proveedor es fallida, se retorna un error
            if ($response->failed()) {
                return response()->json([
                    'error' => 'Failed to initiate payment',
                    'details' => $response->json(),
                ], 400);
            }

            // Iniciamos una transacción para guardar los datos en la base de datos
            \DB::transaction(function () use($validated, $response) {
                Transaction::create([
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'provider' => 'SuperWalletz', 
                    'status' => $response->successful() ? 'pending' : 'failed',
                    'transaction_id' => $response->json()['transaction_id'] ?? null,
                ]);

                // Se guarda un registro de la solicitud y respuesta en los logs
                RequestLogs::create([
                    'provider' => 'SuperWalletz', 
                    'endpoint' => 'http://localhost"3003/pay',
                    'payload' => json_encode($validated),
                    'response' => json_encode($response->json()),
                ]);
            });

            // Se retorna una respuesta de éxito con el 'transaction_id' recibido
            return response()->json([
                'message' => 'Payment initiated successfully',
                'transaction_id' => $response->json()['transaction_id'],
            ]);

        } catch (\Exception $e) {
            // Si ocurre alguna excepción, se retorna un error general con el mensaje de la excepción
            return response()->json([
                'error' => 'An unexpected error occurred',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    // Método que maneja el webhook de SuperWalletz para actualizar el estado de la transacción
    public function handleSuperWalletzWebhook(Request $request)
    {
        $data = $request->all(); // Obtenemos todos los datos del webhook

        // Guardamos el payload del webhook en la base de datos
        Webhook::create([
            'provider' => 'SuperWalletz',
            'payload' => json_encode($request->all()),
        ]);

        // Si el estado es 'success', se actualiza el estado de la transacción a 'success'
        if ($data['status'] === 'success') {

            // Buscamos la transacción correspondiente con el 'transaction_id'
            $pay = Transaction::where('transaction_id', $data['transaction_id'])->first();
            
            if (!$pay) {
                // Si no se encuentra la transacción, se registra un error en los logs
                \Log::error('Transaction not found for transaction_id: ' . $data['transaction_id']);
                return response()->json(['message' => 'Transaction not found for transaction_id: ' . $data['transaction_id']]);
            } 
            
            // Si se encuentra, se actualiza el estado a 'success'
            $pay->status = 'success';
            $pay->save();
        } else {
            // Si el estado es 'failed', se actualiza el estado de la transacción a 'failed'
            $pay = Transaction::where('transaction_id', $data['transaction_id'])->first();
            
            if (!$pay) {
                // Si no se encuentra la transacción, se registra un error en los logs
                \Log::error('Transaction not found for transaction_id: ' . $data['transaction_id']);
                return response()->json(['message' => 'Transaction not found for transaction_id: ' . $data['transaction_id']]);
            }
            // Si se encuentra, se actualiza el estado a 'failed'
            $pay->status = 'failed';
            $pay->save();
        }
    
        // Se retorna una respuesta de éxito indicando que el webhook fue recibido y procesado correctamente
        return response()->json(['message' => 'Webhook received successfully']);
    }
}
