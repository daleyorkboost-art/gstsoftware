<?php
declare(strict_types=1);
namespace App;
final class DocumentData
{
    public static function stateName(string $code): string
    {
        $states = [
            "10" => "Bihar",
            "11" => "Sikkim",
            "12" => "Arunachal Pradesh",
            "13" => "Nagaland",
            "14" => "Manipur",
            "15" => "Mizoram",
            "16" => "Tripura",
            "17" => "Meghalaya",
            "18" => "Assam",
            "19" => "West Bengal",
            "20" => "Jharkhand",
            "21" => "Odisha",
            "22" => "Chhattisgarh",
            "23" => "Madhya Pradesh",
            "24" => "Gujarat",
            "25" => "Daman & Diu (legacy)",
            "26" => "Dadra & Nagar Haveli and Daman & Diu",
            "27" => "Maharashtra",
            "28" => "Andhra Pradesh (legacy)",
            "29" => "Karnataka",
            "30" => "Goa",
            "31" => "Lakshadweep",
            "32" => "Kerala",
            "33" => "Tamil Nadu",
            "34" => "Puducherry",
            "35" => "Andaman & Nicobar",
            "36" => "Telangana",
            "37" => "Andhra Pradesh",
            "38" => "Ladakh",
            "97" => "Other territory",
            "01" => "Jammu & Kashmir",
            "02" => "Himachal Pradesh",
            "03" => "Punjab",
            "04" => "Chandigarh",
            "05" => "Uttarakhand",
            "06" => "Haryana",
            "07" => "Delhi",
            "08" => "Rajasthan",
            "09" => "Uttar Pradesh",
        ];
        return $states[$code] ?? $code;
    }
    public static function financialYear(string $date): string
    {
        $year = (int) substr($date, 0, 4);
        $start = (int) substr($date, 5, 2) < 4 ? $year - 1 : $year;
        return $start . '-' . substr((string) ($start + 1), -2);
    }
}
