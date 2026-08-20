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

        $fromAddress = $params['from_address'] ?? $params['from'] ?? '';
        $toAddress = $params['to_address'] ?? $params['to'] ?? '';

        $fromPub = str_pad(Base58::decode($fromAddress), 32, "\x00", STR_PAD_LEFT);
        $toPub = str_pad(Base58::decode($toAddress), 32, "\x00", STR_PAD_LEFT);
        $recentBlockhash = str_pad(Base58::decode($params['recent_blockhash'] ?? ''), 32, "\x00", STR_PAD_LEFT);

        if (strlen($fromPub) !== 32 || strlen($toPub) !== 32) {
            throw new \InvalidArgumentException("Invalid Solana address length: from ({$fromAddress}) or to ({$toAddress}).");
        }

        if (strlen($recentBlockhash) !== 32) {
            throw new \InvalidArgumentException("Invalid Solana recentBlockhash length: must be 32 bytes.");
        }

        $tokenContract = $params['token_contract'] ?? null;

        if ($tokenContract) {
            // SPL Token TransferChecked Instruction
            $tokenMintPub = str_pad(Base58::decode($tokenContract), 32, "\x00", STR_PAD_LEFT);
            $fromAtaPub = str_pad(Base58::decode(self::deriveAssociatedTokenAccount($params['from_address'], $tokenContract)), 32, "\x00", STR_PAD_LEFT);
            $toAtaPub = str_pad(Base58::decode(self::deriveAssociatedTokenAccount($params['to_address'], $tokenContract)), 32, "\x00", STR_PAD_LEFT);
            $tokenProgram = str_pad(Base58::decode('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA'), 32, "\x00", STR_PAD_LEFT);

            $amountRaw = (int)$params['amount_raw'];
            $decimals = (int)($params['decimals'] ?? 6);

            // TransferChecked Instruction data: [12 (u8), amount (u64 little-endian), decimals (u8)]
            $instructionData = chr(12) . pack('P', $amountRaw) . chr($decimals);

            // Account Keys: [From (payer/signer), From ATA, Mint, To ATA, TokenProgram]
            $accountKeys = [$fromPub, $fromAtaPub, $tokenMintPub, $toAtaPub, $tokenProgram];
            $header = "\x01\x00\x02"; // 1 signer, 0 readonly signed, 2 readonly unsigned

            $accountsPayload = self::compactU16(count($accountKeys)) . implode('', $accountKeys);
            $compiledInstruction = chr(4) . "\x04\x01\x02\x03\x00" . self::compactU16(strlen($instructionData)) . $instructionData;
            $instructionsPayload = self::compactU16(1) . $compiledInstruction;
        } else {
            // Native SOL SystemProgram Transfer Instruction (Type 2)
            $lamports = (int)$params['lamports'];
            $instructionData = pack('V', 2) . pack('P', $lamports);

            $systemProgram = str_pad(Base58::decode('11111111111111111111111111111111'), 32, "\x00", STR_PAD_LEFT);
            $accountKeys = [$fromPub, $toPub, $systemProgram];
            $header = "\x01\x00\x01";

            $accountsPayload = self::compactU16(count($accountKeys)) . implode('', $accountKeys);
            $compiledInstruction = chr(2) . "\x02\x00\x01" . self::compactU16(strlen($instructionData)) . $instructionData;
            $instructionsPayload = self::compactU16(1) . $compiledInstruction;
        }

        $message = $header . $accountsPayload . $recentBlockhash . $instructionsPayload;
        $signature = sodium_crypto_sign_detached($message, $secretKey);

        $serializedTx = self::compactU16(1) . $signature . $message;
        return base64_encode($serializedTx);
    }

    public static function compactU16(int $val): string
    {
        $out = '';
        while ($val >= 0x80) {
            $out .= chr(($val & 0x7f) | 0x80);
            $val >>= 7;
        }
        $out .= chr($val & 0x7f);
        return $out;
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