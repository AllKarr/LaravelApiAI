<?php

namespace App\Http\Controllers;

use App\Models\ChatHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ChatbotController extends Controller
{
    public function chat(Request $request)
    {
        try {
            $request->validate([
                'message' => 'required|string',
                'session_id' => 'nullable|string', // Fixed UUID validation issue
            ]);

            // Ensure user is authenticated
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // Use existing session ID or generate a new one
            $session_id = $request->session_id ?? (string) Str::uuid();

            // Retrieve chat history if session_id exists
            $previousMessages = ChatHistory::where('user_id', $user->id)
                ->where('session_id', $session_id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($chat) => [
                    ['role' => 'user', 'content' => $chat->user_message],
                    ['role' => 'assistant', 'content' => $chat->bot_response],
                ])
                ->flatten(1)
                ->toArray();

            // Add new message to the conversation
            $messages = array_merge($previousMessages, [
                ['role' => 'user', 'content' => $request->message]
            ]);

            // Send messages to Ollama API
            try {
                $response = Http::timeout(120)->post('http://127.0.0.1:11434/api/chat', [
                    'model' => 'mistral',
                    'messages' => $messages,
                    'stream' => false
                ]);

                if ($response->failed()) {
                    return response()->json(['message' => 'Failed to get response from AI'], 500);
                }

                $responseData = $response->json();
                
                // Ensure the response is a string and not an array
                if (is_array($responseData)) {
                    $botResponse = $responseData['message'] ?? ($responseData['response'] ?? 'No response from AI');
                    
                    if (is_array($botResponse)) {
                        $botResponse = json_encode($botResponse); // Convert array to JSON string
                    }
                } else {
                    $botResponse = (string) $responseData;
                }

            } catch (\Exception $e) {
                return response()->json(['message' => 'Ollama API error: ' . $e->getMessage()], 500);
            }

            // Save chat history
            ChatHistory::create([
                'user_id' => $user->id,
                'session_id' => $session_id,
                'user_message' => $request->message,
                'bot_response' => $botResponse,
            ]);

            return response()->json([
                'session_id' => $session_id,
                'response' => $botResponse,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Chat request failed',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 400);
        }
    }
}
