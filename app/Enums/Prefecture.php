<?php

namespace App\Enums;

/**
 * 都道府県。値は JIS X 0401 の番号（docs/domain-api-contract.md M2.0）。
 * SPA は同じ 47 件を frontend/src/constants/prefectures.ts に持つ。
 */
enum Prefecture: int
{
    case Hokkaido = 1;
    case Aomori = 2;
    case Iwate = 3;
    case Miyagi = 4;
    case Akita = 5;
    case Yamagata = 6;
    case Fukushima = 7;
    case Ibaraki = 8;
    case Tochigi = 9;
    case Gunma = 10;
    case Saitama = 11;
    case Chiba = 12;
    case Tokyo = 13;
    case Kanagawa = 14;
    case Niigata = 15;
    case Toyama = 16;
    case Ishikawa = 17;
    case Fukui = 18;
    case Yamanashi = 19;
    case Nagano = 20;
    case Gifu = 21;
    case Shizuoka = 22;
    case Aichi = 23;
    case Mie = 24;
    case Shiga = 25;
    case Kyoto = 26;
    case Osaka = 27;
    case Hyogo = 28;
    case Nara = 29;
    case Wakayama = 30;
    case Tottori = 31;
    case Shimane = 32;
    case Okayama = 33;
    case Hiroshima = 34;
    case Yamaguchi = 35;
    case Tokushima = 36;
    case Kagawa = 37;
    case Ehime = 38;
    case Kochi = 39;
    case Fukuoka = 40;
    case Saga = 41;
    case Nagasaki = 42;
    case Kumamoto = 43;
    case Oita = 44;
    case Miyazaki = 45;
    case Kagoshima = 46;
    case Okinawa = 47;
}
