<?php

namespace BlockchainSdk\Tests;

use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\MasterWalletDerivation;
use BlockchainSdk\Drivers\Bitcoin\BitcoinDriver;
use BlockchainSdk\Drivers\Evm\EvmDriver;
use BlockchainSdk\Drivers\Litecoin\LitecoinDriver;
use BlockchainSdk\Drivers\Solana\SolanaDriver;
use BlockchainSdk\Drivers\Tron\TronDriver;
use BlockchainSdk\Drivers\Xrp\XrpDriver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MasterWalletDerivationTest extends TestCase
{
    public function test_standard_phrase_derives_expected_keys_for_all_supported_driver_families(): void
    {
        $derivation = new MasterWalletDerivation;
        $seed = $derivation->seedFromMnemonic(str_repeat('abandon ', 11).'about');
        $this->assertSame('5eb00bbddcf069084889a8ab9155568165f5c453ccb85e70811aaed6f6da5fc19a5ac40b389cd370d086206dec8aa6c43daea6690f20ad3d8d48b2d2ce9e38e4', bin2hex($seed));

        $cases = [
            [new EvmDriver([]), '1ab42cc412b618bdea3a599e3c9bae199ebf030895b039e9db1e30dafb12b727'],
            [new BitcoinDriver([]), '4604b4b710fe91f584fff084e1a9159fe4f8408fff380596a604948474ce4fa3'],
            [new LitecoinDriver([]), '4ab54480cbbaa53d5d3ba43a3e85d221df085490e88346ad11579e17000db7bb'],
            [new TronDriver([]), 'b5a4cea271ff424d7c31dc12a3e43e401df7a40d7412a15750f3f0b6b5449a28'],
            [new XrpDriver([]), '90802a50aa84efb6cdb225f17c27616ea94048c179142fecf03f4712a07ea7a4'],
            [new SolanaDriver([]), '37df573b3ac4ad5b522e064e25b63ea16bcbe79d449e81a0268d1047948bb445'],
        ];

        foreach ($cases as [$driver, $expected]) {
            $wallet = $derivation->deriveWallet($seed, $driver);
            $actual = $driver instanceof SolanaDriver
                ? bin2hex(substr(Base58::decode($wallet->privateKey), 0, 32))
                : substr($wallet->privateKey, 2);
            $this->assertSame($expected, $actual);
            $this->assertTrue($driver->validateAddress($wallet->address));
        }
    }

    public function test_solana_derivation_matches_the_four_level_wallet_core_vector(): void
    {
        $derivation = new MasterWalletDerivation;
        $seed = $derivation->seedFromMnemonic('team engine square letter hero song dizzy scrub tornado fabric divert saddle');

        $wallet = $derivation->deriveWallet($seed, new SolanaDriver([]));

        $this->assertSame('CgWJeEWkiYqosy1ba7a3wn9HAQuHyK48xs3LM4SSDc1C', $wallet->address);
    }

    public function test_generated_phrases_have_twenty_four_words_and_a_valid_checksum(): void
    {
        $derivation = new MasterWalletDerivation;
        $phrase = $derivation->generateMnemonic();

        $this->assertCount(24, explode(' ', $phrase));
        $this->assertSame(64, strlen($derivation->seedFromMnemonic($phrase)));
    }

    public function test_invalid_checksum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MasterWalletDerivation)->seedFromMnemonic(trim(str_repeat('abandon ', 12)));
    }
}
