<?php

namespace App\Support\Laboratory;

/**
 * Names a new lab can start from instead of typing them all in.
 *
 * OPT-IN AND EDITABLE. Nothing here is written by a migration or at
 * provisioning; a person presses "Load starter list" on one list, gets the rows
 * that list does not already have, and renames or removes what does not suit
 * them. A row they already have, under any case, is left alone.
 *
 * VOCABULARY ONLY. These are the names of sections, tubes, sample types, units,
 * methods and rejection reasons as labs commonly write them. There is no
 * reference range, critical limit or any other clinical value in this file, and
 * there must never be: those are decisions a qualified person makes for their
 * own lab and analyser.
 *
 * Test categories, equipment and partner labs have no starter: they are each
 * lab's own.
 */
final class StarterLists
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function for(string $master): array
    {
        return match ($master) {
            LabMasters::DEPARTMENTS => self::departments(),
            LabMasters::METHODS => self::methods(),
            LabMasters::UNITS => self::units(),
            LabMasters::CONTAINERS => self::containers(),
            LabMasters::SAMPLE_TYPES => self::sampleTypes(),
            LabMasters::REJECTION_REASONS => self::rejectionReasons(),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private static function departments(): array
    {
        return [
            ['name' => 'Haematology', 'code' => 'HAEM'],
            ['name' => 'Clinical Biochemistry', 'code' => 'BIO'],
            ['name' => 'Clinical Pathology', 'code' => 'CP'],
            ['name' => 'Microbiology', 'code' => 'MICRO'],
            ['name' => 'Serology', 'code' => 'SERO'],
            ['name' => 'Immunology', 'code' => 'IMM'],
            ['name' => 'Histopathology', 'code' => 'HISTO'],
            ['name' => 'Cytopathology', 'code' => 'CYTO'],
            ['name' => 'Molecular Diagnostics', 'code' => 'MOL'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function methods(): array
    {
        return [
            ['name' => 'Automated haematology analyser'],
            ['name' => 'Spectrophotometry'],
            ['name' => 'Enzymatic'],
            ['name' => 'Ion-selective electrode (ISE)'],
            ['name' => 'Chemiluminescence immunoassay (CLIA)'],
            ['name' => 'ELISA'],
            ['name' => 'Immunoturbidimetry'],
            ['name' => 'Immunochromatography (rapid card)'],
            ['name' => 'HPLC'],
            ['name' => 'Microscopy'],
            ['name' => 'Culture and sensitivity'],
            ['name' => 'Agglutination'],
            ['name' => 'Westergren'],
            ['name' => 'RT-PCR'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function units(): array
    {
        return [
            ['symbol' => 'g/dL', 'name' => 'Grams per decilitre'],
            ['symbol' => 'mg/dL', 'name' => 'Milligrams per decilitre'],
            ['symbol' => 'µg/dL', 'name' => 'Micrograms per decilitre'],
            ['symbol' => 'ng/dL', 'name' => 'Nanograms per decilitre'],
            ['symbol' => 'g/L', 'name' => 'Grams per litre'],
            ['symbol' => 'mg/L', 'name' => 'Milligrams per litre'],
            ['symbol' => 'mmol/L', 'name' => 'Millimoles per litre'],
            ['symbol' => 'µmol/L', 'name' => 'Micromoles per litre'],
            ['symbol' => 'mEq/L', 'name' => 'Milliequivalents per litre'],
            ['symbol' => 'U/L', 'name' => 'Units per litre'],
            ['symbol' => 'IU/L', 'name' => 'International units per litre'],
            ['symbol' => 'µIU/mL', 'name' => 'Micro-international units per millilitre'],
            ['symbol' => 'mIU/L', 'name' => 'Milli-international units per litre'],
            ['symbol' => 'ng/mL', 'name' => 'Nanograms per millilitre'],
            ['symbol' => 'pg/mL', 'name' => 'Picograms per millilitre'],
            ['symbol' => '%', 'name' => 'Per cent'],
            ['symbol' => 'fL', 'name' => 'Femtolitres'],
            ['symbol' => 'pg', 'name' => 'Picograms'],
            ['symbol' => '/cumm', 'name' => 'Per cubic millimetre'],
            ['symbol' => 'lakhs/cumm', 'name' => 'Lakhs per cubic millimetre'],
            ['symbol' => 'million/cumm', 'name' => 'Million per cubic millimetre'],
            ['symbol' => '10³/µL', 'name' => 'Thousands per microlitre'],
            ['symbol' => '10⁶/µL', 'name' => 'Millions per microlitre'],
            ['symbol' => 'mm/hr', 'name' => 'Millimetres per hour'],
            ['symbol' => 'sec', 'name' => 'Seconds'],
            ['symbol' => 'ratio', 'name' => 'Ratio'],
            ['symbol' => 'mL/min/1.73m²', 'name' => 'Millilitres per minute per 1.73 m²'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function containers(): array
    {
        return [
            ['name' => 'Plain tube', 'cap_colour' => 'red', 'additive' => 'Clot activator'],
            ['name' => 'Gel separator tube (SST)', 'cap_colour' => 'gold', 'additive' => 'Clot activator and gel'],
            ['name' => 'EDTA tube', 'cap_colour' => 'lavender', 'additive' => 'K2 EDTA'],
            ['name' => 'Citrate tube', 'cap_colour' => 'light_blue', 'additive' => 'Sodium citrate 3.2%'],
            ['name' => 'Fluoride tube', 'cap_colour' => 'grey', 'additive' => 'Sodium fluoride / potassium oxalate'],
            ['name' => 'Heparin tube', 'cap_colour' => 'green', 'additive' => 'Lithium heparin'],
            ['name' => 'ESR tube', 'cap_colour' => 'black', 'additive' => 'Sodium citrate 3.8%'],
            ['name' => 'Sterile urine container', 'cap_colour' => 'white'],
            ['name' => 'Sterile stool container', 'cap_colour' => 'white'],
            ['name' => 'Blood culture bottle'],
            ['name' => 'Swab with transport medium'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function sampleTypes(): array
    {
        return [
            ['name' => 'Whole blood', 'code' => 'WB'],
            ['name' => 'Serum', 'code' => 'SER'],
            ['name' => 'EDTA plasma', 'code' => 'EDTA'],
            ['name' => 'Citrate plasma', 'code' => 'CIT'],
            ['name' => 'Fluoride plasma', 'code' => 'FLU'],
            ['name' => 'Urine (random)', 'code' => 'UR'],
            ['name' => 'Urine (24-hour)', 'code' => 'U24'],
            ['name' => 'Stool', 'code' => 'ST'],
            ['name' => 'Sputum', 'code' => 'SP'],
            ['name' => 'Cerebrospinal fluid', 'code' => 'CSF'],
            ['name' => 'Body fluid', 'code' => 'BF'],
            ['name' => 'Swab', 'code' => 'SW'],
            ['name' => 'Tissue', 'code' => 'TIS'],
            ['name' => 'Semen', 'code' => 'SEM'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function rejectionReasons(): array
    {
        return [
            ['reason' => 'Haemolysed sample', 'requires_recollection' => true],
            ['reason' => 'Clotted sample', 'requires_recollection' => true],
            ['reason' => 'Insufficient quantity (QNS)', 'requires_recollection' => true],
            ['reason' => 'Lipaemic sample', 'requires_recollection' => true],
            ['reason' => 'Wrong container', 'requires_recollection' => true],
            ['reason' => 'Wrong sample type', 'requires_recollection' => true],
            ['reason' => 'Unlabelled or mislabelled sample', 'requires_recollection' => true],
            ['reason' => 'Patient identity mismatch', 'requires_recollection' => true],
            ['reason' => 'Container leaked or broken', 'requires_recollection' => true],
            ['reason' => 'Delayed beyond sample stability', 'requires_recollection' => true],
            ['reason' => 'Duplicate sample', 'requires_recollection' => false],
        ];
    }
}
