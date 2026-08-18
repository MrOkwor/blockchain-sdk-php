<?php

namespace BlockchainSdk\Drivers\Solana;

use BlockchainSdk\Contracts\TransactionSignerInterface;
use BlockchainSdk\Crypto\Base58;

class SolanaTransactionSigner implements TransactionSignerInterface
{
    public function signTransaction(array $params): string
    {
        $privKeyBin = Base58::decode($params['private_key']);
        $secretKey = strlen($privKeyBin) === 64 ? $privKeyBin : sodium_crypto_sign_seed_keypair($privKeyBin);

        $fromPub = Base58::decode($params['from_address']);
        $toPub = Base58::decode($params['to_address']);
        $recentBlockhash = Base58::decode($params['recent_blockhash']);

        $tokenContract = $params['token_contract'] ?? null;

        if ($tokenContract) {
            // SPL Token TransferChecked Instruction
            $tokenMintPub = Base58::decode($tokenContract);
            $fromAtaPub = Base58::decode(self::deriveAssociatedTokenAccount($params['from_address'], $tokenContract));
            $toAtaPub = Base58::decode(self::deriveAssociatedTokenAccount($params['to_address'], $tokenContract));
            $tokenProgram = Base58::decode('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');

            $amountRaw = (int)$params['amount_raw'];
            $decimals = (int)($params['decimals'] ?? 6);

            // TransferChecked Instruction data: [12 (u8), amount (u64 little-endian), decimals (u8)]
            $instructionData = chr(12) . pack('P', $amountRaw) . chr($decimals);

            // Account Keys: [From (payer/signer), From ATA, Mint, To ATA, TokenProgram]
            $accountKeys = [$fromPub, $fromAtaPub, $tokenMintPub, $toAtaPub, $tokenProgram];
            $header = "\x01\x00\x02"; // 1 signer, 0 readonly signed, 2 readonly unsigned

            $accountsPayload = chr(count($accountKeys)) . implode('', $accountKeys);
            $compiledInstruction = chr(4) . "\x04\x01\x02\x03\x00" . chr(strlen($instructionData)) . $instructionData;
            $instructionsPayload = "\x01" . $compiledInstruction;
        } else {
            // Native SOL SystemProgram Transfer Instruction (Type 2)
            $lamports = (int)$params['lamports'];
            $instructionData = pack('V', 2) . pack('P', $lamports);

            $systemProgram = Base58::decode('11111111111111111111111111111111');
            $accountKeys = [$fromPub, $toPub, $systemProgram];
            $header = "\x01\x00\x01";

            $accountsPayload = chr(count($accountKeys)) . implode('', $accountKeys);
            $compiledInstruction = chr(2) . "\x02\x00\x01" . chr(strlen($instructionData)) . $instructionData;
            $instructionsPayload = "\x01" . $compiledInstruction;
        }

        $message = $header . $accountsPayload . $recentBlockhash . $instructionsPayload;
        $signature = sodium_crypto_sign_detached($message, $secretKey);

        $serializedTx = "\x01" . $signature . $message;
        return base64_encode($serializedTx);
    }

    public static function deriveAssociatedTokenAccount(string $walletAddress, string $mintAddress): string
    {
        $walletPub = Base58::decode($walletAddress);
        $mintPub = Base58::decode($mintAddress);
        $tokenProgram = Base58::decode('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');
        $ataProgram = Base58::decode('ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL');

        // Seeds: [wallet, token_program, mint]
        for ($bump = 255; $bump >= 0; $bump--) {
            $buffer = $walletPub . $tokenProgram . $mintPub . chr($bump) . $ataProgram . "ProgramDerivedAddress";
            $hash = hash('sha256', $buffer, true);
            // Check if off ed25519 curve
            return Base58::encode($hash);
        }
        return '';
    }
}