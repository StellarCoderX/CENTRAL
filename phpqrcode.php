 <?php
/*
 * PHP QR Code encoder
 *
 * Config file, feel free to edit
 */
 
 define('QR_CACHEABLE', true);
 define('QR_CACHE_DIR', false);
 define('QR_LOG_DIR', false);
 define('QR_FIND_BEST_MASK', true);
 define('QR_FIND_FROM_RANDOM', false);
 define('QR_DEFAULT_MASK', 2);
 define('QR_PNG_MAXIMUM_SIZE', 1024);

// ####################################################################
// ####################################################################

/*
 * QR Code class
 *
 * Main class that generates QR Code
 */
 
class QRcode
{
    public $version;
    public $width;
    public $data;

    // ------------------------------------------------------------------
    
    public static function png($text, $outfile = false, $level = QR_ECLEVEL_L, $size = 3, $margin = 4, $saveandprint=false)
    {
        $enc = QRencode::factory($level, $size, $margin);
        return $enc->encodePNG($text, $outfile, $saveandprint=false);
    }
    
    // ------------------------------------------------------------------
    
    public static function raw($text, $outfile = false, $level = QR_ECLEVEL_L, $size = 3, $margin = 4)
    {
        $enc = QRencode::factory($level, $size, $margin);
        return $enc->encode($text, $outfile);
    }
}
 
// ####################################################################
// ####################################################################
 
/*
 * Encoder class
 */
 
class QRencode
{
    public $casesensitive = true;
    public $eightbit = false;

    public $version = 0;
    public $size = 3;
    public $margin = 4;

    public $structured = 0; // not supported yet

    public $level = QR_ECLEVEL_L;
    public $hint = QR_MODE_8;

    // ------------------------------------------------------------------
    
    public static function factory($level = QR_ECLEVEL_L, $size = 3, $margin = 4)
    {
        $encoder = new QRencode();
        $encoder->size = $size;
        $encoder->margin = $margin;
        
        switch ($level.'') {
            case '0':
            case '1':
            case '2':
            case '3':
                $encoder->level = $level;
                break;
            case 'l':
            case 'L':
                $encoder->level = QR_ECLEVEL_L;
                break;
            case 'm':
            case 'M':
                $encoder->level = QR_ECLEVEL_M;
                break;
            case 'q':
            case 'Q':
                $encoder->level = QR_ECLEVEL_Q;
                break;
            case 'h':
            case 'H':
                $encoder->level = QR_ECLEVEL_H;
                break;
        }
        
        return $encoder;
    }
    
    // ------------------------------------------------------------------
    
    public function encode($text, $outfile = false)
    {
        $this->hint = QR_MODE_8;
        
        if($this->eightbit)
            $this->hint = QR_MODE_8;

        $tab = QRspec::getEccTables($this->level);
        $this->version = QRspec::getMinimumVersion((strlen($text) << 3), $this->level, $this->hint, $this->casesensitive, $tab);
        
        if($this->version < 0) {
            return false;
        }
        
        if($this->version > QRSPEC_VERSION_MAX) {
            return false;
        }

        $qr = new QRcode();
        $qr->version = $this->version;
        $qr->width = QRspec::$capacity[$this->version][QRCAP_WIDTH];
        $qr->data = array_fill(0, $qr->width, array_fill(0, $qr->width, 0));
        
        $spec = QRspec::getEncodedBitstream($this->version, $this->level, $text, $this->hint, $this->casesensitive, $tab);
        
        $mask = QRmask::getMask($qr, $this->level, $spec);
        
        if($mask == -1)
            return NULL;
            
        QRspec::putSource($qr, $spec);

        $qr->data = QRmask::writeFormatInformation($qr, $this->level, $mask);
        
        $qr->data = QRmask::maskData($qr, $mask);
        
        return $qr;
    }
    
    // ------------------------------------------------------------------
    
    public function encodePNG($text, $outfile = false, $saveandprint=false)
    {
        try {
        
            ob_start();
            $qr = $this->encode($text);
            if($qr === NULL) {
                ob_end_clean();
                return;
            }
            
            $img = QRimage::image($qr, $this->size, $this->margin);
            ob_end_clean();
            
            if ($outfile === false) {
                header("Content-type: image/png");
                ImagePng($img);
            } else {
                if($saveandprint === true){
                    ImagePng($img, $outfile);
                    header("Content-type: image/png");
                    ImagePng($img);
                } else {
                    ImagePng($img, $outfile);
                }
            }
            
            ImageDestroy($img);
        
        } catch (Exception $e) {
        
            QRtools::log($outfile, $e->getMessage());
        
        }
    }
}
 
// ####################################################################
// ####################################################################
 
/*
 * Base class for QRcode data handling
 */
 
abstract class QRtools
{
    // ------------------------------------------------------------------
    
    public static function binarize($frame)
    {
        $len = count($frame);
        foreach ($frame as &$frameLine) {
        
            for($i=0; $i<$len; $i++) {
                $frameLine[$i] = (ord($frameLine[$i])&1)?'1':'0';
            }
        }
        
        return $frame;
    }
    
    // ------------------------------------------------------------------
    
    public static function dump($frame, $with_border = true)
    {
        if ($with_border)
            $margin = 4;
        else
            $margin = 0;
            
        $width = count($frame) + $margin * 2;
        
        $out = str_repeat('0', $width)."\n";
        $out .= str_repeat('0', $width)."\n";
        $out .= str_repeat('0', $width)."\n";
        $out .= str_repeat('0', $width)."\n";
        
        foreach($frame as $line) {
            $out .= '0000';
            $out .= implode('', $line);
            $out .= '0000';
            $out .= "\n";
        }

        $out .= str_repeat('0', $width)."\n";
        $out .= str_repeat('0', $width)."\n";
        $out .= str_repeat('0', $width)."\n";
        $out .= str_repeat('0', $width)."\n";
        
        return $out;
    }
    
    // ------------------------------------------------------------------
    
    public static function log($outfile, $err)
    {
        if(QR_LOG_DIR !== false) {
            if ($outfile !== false) {
                $log = @fopen(QR_LOG_DIR.basename($outfile).'-log.txt', 'a');
                if($log) {
                    fwrite($log, $err."\n");
                    fclose($log);
                }
            }
        }
    }
}
 
// ####################################################################
// ####################################################################
 
/*
 * Drawing class
 */
 
class QRimage
{
    // ------------------------------------------------------------------
    
    public static function image(QRcode $qr, $size, $margin)
    {
        $width = $qr->width;
        
        $fullWidth = $width + $margin*2;
        
        if($size > 0)
            $fullWidth *= $size;

        if ($fullWidth > QR_PNG_MAXIMUM_SIZE) {
            throw new Exception('Maximum image size exceeded.');
        }

        $img = ImageCreate($fullWidth, $fullWidth);
        
        $col[0] = ImageColorAllocate($img,255,255,255);
        $col[1] = ImageColorAllocate($img,0,0,0);

        ImageFilledRectangle($img, 0, 0, $fullWidth, $fullWidth, $col[0]);

        if($size > 0) {
            for($y=0; $y<$width; $y++) {
                for($x=0; $x<$width; $x++) {
                    if ($qr->data[$y][$x] & 1) {
                        ImageFilledRectangle($img, ($x+$margin)*$size, ($y+$margin)*$size, ($x+$margin+1)*$size-1, ($y+$margin+1)*$size-1, $col[1]);
                    }
                }
            }
        } else {
            for($y=0; $y<$width; $y++) {
                for($x=0; $x<$width; $x++) {
                    if ($qr->data[$y][$x] & 1) {
                        ImageSetPixel($img, $x+$margin, $y+$margin, $col[1]);
                    }
                }
            }
        }
        
        return $img;
    }
}
 
// ####################################################################
// ####################################################################
 
/*
 * Specification tables
 */
 
 define('QR_MODE_NUL', -1);
 define('QR_MODE_NUM', 0);
 define('QR_MODE_AN', 1);
 define('QR_MODE_8', 2);
 define('QR_MODE_KANJI', 3);
 define('QR_MODE_STRUCTURE', 4);

 define('QR_ECLEVEL_L', 0);
 define('QR_ECLEVEL_M', 1);
 define('QR_ECLEVEL_Q', 2);
 define('QR_ECLEVEL_H', 3);

 define('QRSPEC_VERSION_MAX', 40);
 define('QRSPEC_WIDTH_MAX', 177);

 define('QRCAP_WIDTH', 0);
 define('QRCAP_WORDS', 1);
 define('QRCAP_REMINDER', 2);
 define('QRCAP_EC', 3);
 
// ####################################################################
// ####################################################################
 
/*
 * QR Specification class
 */
 
class QRspec
{
    public static $capacity = array(
        array(  0,    0, 0, array(   0,    0,    0,    0)),
        array( 21,   26, 0, array(   7,   10,   13,   17)),
        array( 25,   44, 7, array(  10,   16,   22,   28)),
        array( 29,   70, 7, array(  15,   26,   36,   44)),
        array( 33,  100, 7, array(  20,   36,   52,   64)),
        array( 37,  134, 7, array(  26,   48,   72,   88)),
        array( 41,  172, 7, array(  36,   64,   96,  112)),
        array( 45,  196, 0, array(  40,   72,  108,  130)),
        array( 49,  242, 0, array(  48,   88,  132,  156)),
        array( 53,  292, 0, array(  60,  110,  160,  192)),
        array( 57,  346, 0, array(  72,  130,  192,  224)),
        array( 61,  404, 0, array(  80,  150,  224,  264)),
        array( 65,  466, 0, array(  96,  176,  260,  308)),
        array( 69,  532, 0, array( 104,  198,  288,  352)),
        array( 73,  581, 3, array( 120,  216,  320,  384)),
        array( 77,  655, 3, array( 132,  240,  360,  432)),
        array( 81,  733, 3, array( 144,  280,  408,  480)),
        array( 85,  815, 3, array( 168,  308,  448,  532)),
        array( 89,  901, 3, array( 180,  338,  504,  588)),
        array( 93,  991, 3, array( 196,  364,  546,  650)),
        array( 97, 1085, 3, array( 224,  416,  600,  700)),
        array(101, 1156, 4, array( 224,  442,  644,  750)),
        array(105, 1258, 4, array( 252,  476,  690,  816)),
        array(109, 1364, 4, array( 270,  504,  750,  900)),
        array(113, 1474, 4, array( 300,  560,  810,  960)),
        array(117, 1588, 4, array( 312,  588,  870, 1050)),
        array(121, 1706, 4, array( 336,  644,  952, 1110)),
        array(125, 1828, 4, array( 360,  700, 1020, 1200)),
        array(129, 1921, 3, array( 390,  728, 1050, 1260)),
        array(133, 2051, 3, array( 420,  784, 1140, 1350)),
        array(137, 2185, 3, array( 450,  812, 1200, 1440)),
        array(141, 2323, 3, array( 480,  868, 1290, 1530)),
        array(145, 2465, 3, array( 510,  924, 1350, 1620)),
        array(149, 2611, 3, array( 540,  980, 1440, 1710)),
        array(153, 2761, 3, array( 570, 1036, 1530, 1800)),
        array(157, 2876, 0, array( 570, 1064, 1590, 1890)),
        array(161, 3034, 0, array( 600, 1120, 1680, 1980)),
        array(165, 3196, 0, array( 630, 1204, 1770, 2100)),
        array(169, 3362, 0, array( 660, 1260, 1860, 2220)),
        array(173, 3532, 0, array( 720, 1316, 1950, 2310)),
        array(177, 3706, 0, array( 750, 1372, 2040, 2430))
    );

    // ------------------------------------------------------------------
    
    public static function getEccTables($level)
    {
        return QRrs::getEccTables($level);
    }

    // ------------------------------------------------------------------
    
    public static function getMinimumVersion($width, $level, $hint, $casesensitive, $tab)
    {
        for($i=1; $i <= QRSPEC_VERSION_MAX; $i++) {
            $words  = self::$capacity[$i][QRCAP_WORDS];
            $bits = QRinput::estimateBitWidth($i, $hint, $width, $casesensitive);
            if($bits < 0)
                return -1;
            $w = ($bits + 7) >> 3;
            if($w <= $words - $tab[$i][0])
                return $i;
        }
        
        return -1;
    }
    
    // ------------------------------------------------------------------
    
    public static function getEncodedBitstream($version, $level, $text, $hint, $casesensitive, $tab)
    {
        $input = new QRinput($version, $hint, $casesensitive);
        $input->append($text, strlen($text));
        
        $words = self::$capacity[$version][QRCAP_WORDS];
        $spec = $input->getBitstream();
        
        $slen = strlen($spec);
        $rem = self::$capacity[$version][QRCAP_REMINDER];
        
        $bits = $slen + $rem;
        $w = ($bits+7) >> 3;

        if($w > $words - $tab[$version][0]) {
            return NULL;
        }
        
        if( ($slen + $rem) > ($words << 3) ) {
            return NULL;
        }
        
        if($slen & 7) {
            $padlen = 8 - ($slen & 7);
            $spec .= str_repeat('0', $padlen);
        }
        
        $padwords = $words - (($slen + $rem) >> 3);

        if($padwords > 0) {
        
            $pad = array(0xec, 0x11);
            for($i=0; $i<$padwords; $i++) {
                $spec .= pack('C', $pad[$i%2]);
            }
        }

        $encoded = QRrs::encode($spec, $version, $level, $tab);

        if($encoded == NULL) return NULL;
        
        return QRspec::binarize($encoded);
    }
    
    // ------------------------------------------------------------------
    
    public static function binarize($spec)
    {
        $res = '';
        $len = strlen($spec);
        for($i=0; $i<$len; $i++) {
            $s = decbin(ord($spec[$i]));
            $res .= str_repeat('0', 8-strlen($s)).$s;
        }
        
        return $res;
    }
    
    // ------------------------------------------------------------------
    
    public static function getFinderPattern()
    {
        $pattern = array();
        
        $pattern[0] = array(1,1,1,1,1,1,1);
        $pattern[1] = array(1,0,0,0,0,0,1);
        $pattern[2] = array(1,0,1,1,1,0,1);
        $pattern[3] = array(1,0,1,1,1,0,1);
        $pattern[4] = array(1,0,1,1,1,0,1);
        $pattern[5] = array(1,0,0,0,0,0,1);
        $pattern[6] = array(1,1,1,1,1,1,1);
        
        return $pattern;
    }

    // ------------------------------------------------------------------
    
    public static function getAlignmentPattern($version)
    {
        $pattern = array();
        
        if($version < 2)
            return $pattern;
            
        $pattern[0] = array(1,1,1,1,1);
        $pattern[1] = array(1,0,0,0,1);
        $pattern[2] = array(1,0,1,0,1);
        $pattern[3] = array(1,0,0,0,1);
        $pattern[4] = array(1,1,1,1,1);
        
        return $pattern;
    }
    
    // ------------------------------------------------------------------
    
    public static function putSource(QRcode $qr, $spec)
    {
        $width = $qr->width;
        
        $finder = self::getFinderPattern();
        
        for($y=0; $y<7; $y++) {
            for($x=0; $x<7; $x++) {
                $qr->data[$y][$x] = $finder[$y][$x] | 0x80;
                $qr->data[$y][$width-1-$x] = $finder[$y][$x] | 0x80;
                $qr->data[$width-1-$y][$x] = $finder[$y][$x] | 0x80;
            }
        }
        
        for($y=0; $y<8; $y++) {
            $qr->data[$y][7] |= 0x80;
            $qr->data[$y][$width-8] |= 0x80;
            
        }
        for($x=0; $x<8; $x++) {
            $qr->data[7][$x] |= 0x80;
            $qr->data[$width-8][$x] |= 0x80;
        }

        if($qr->version >= 2) {
            $alignment = self::getAlignmentPattern($qr->version);
            $size = 5;
            $positions = QRspec::$alignment_pattern[$qr->version];
            
            $px = $positions;
            $py = $positions;
            
            $num = count($positions);
            
            for($ix=0; $ix<$num; $ix++) {
                for($iy=0; $iy<$num; $iy++) {
                    
                    if( ($ix == 0 && $iy == 0)
                     || ($ix == $num -1 && $iy == 0)
                     || ($ix == 0 && $iy == $num - 1)
                    ) continue;
                    
                    for($y=0; $y<5; $y++) {
                        for($x=0; $x<5; $x++) {
                            $qr->data[$py[$iy]-2+$y][$px[$ix]-2+$x] = $alignment[$y][$x] | 0x80;
                        }
                    }
                }
            }
        }
        
        $qr->data[$width-8][8] = 1 | 0x80;
        
        for($i=0; $i<15; $i++) {
            $y = QRspec::$format_info_pos[$i][0];
            $x = QRspec::$format_info_pos[$i][1];
            
            $qr->data[$y][$x] |= 0x80;
        }

        if($qr->version > 6) {
            for($i=0; $i<18; $i++) {
                $y = QRspec::$version_info_pos[$i][0];
                $x = QRspec::$version_info_pos[$i][1];
                $qr->data[$y][$x] |= 0x80;
                $qr->data[$x][$y] |= 0x80;
            }
        }
        
        // timing
        for($i=0; $i<$width; $i++) {
            $qr->data[6][$i] |= 0x80;
            $qr->data[$i][6] |= 0x80;
        }
        for($i=8; $i<$width-8; $i++) {
            $qr->data[6][$i] ^= 1;
            $qr->data[$i][6] ^= 1;
        }
        
        //data
        $datacode = $spec;
        
        $x = $width - 1;
        $y = $width - 1;
        $dir = -1; // up
        
        $end = strlen($datacode);
        for($i=0; $i < $end; $i+=2) {
        
            $bit1 = $datacode[$i];
            
            if($i+1 < $end) {
                $bit2 = $datacode[$i+1];
            } else {
                $bit2 = 0;
            }
            
            if(!$qr->data[$y][$x]) {
                $qr->data[$y][$x] = ($bit1)?1:0;
            }
            $x--;
            if(!$qr->data[$y][$x]) {
                $qr->data[$y][$x] = ($bit2)?1:0;
            }
            $x++;
            
            
            if($x == 6) {
                $x = 5;
            }

            while($qr->data[$y][$x] & 0x80) {
            
                $x--;
                if($x == 6) $x--;
                
                if($x < 0) {
                    $x = $width-1;
                    $y += $dir;
                    if($y < 0 || $y >= $width) {
                        return; // should not happen
                    }
                }
            }
            
            $y += $dir;
            if($y < 0 || $y >= $width) {
                $y -= $dir;
                $dir = -$dir;
                $x -= 2;
            }

        }
    }
    
    // ------------------------------------------------------------------
    
    public static $alignment_pattern = array(
        array(),
        array(),
        array( 6, 18),
        array( 6, 22),
        array( 6, 26),
        array( 6, 30),
        array( 6, 34),
        array( 6, 22, 38),
        array( 6, 24, 42),
        array( 6, 26, 46),
        array( 6, 28, 50),
        array( 6, 30, 54),
        array( 6, 32, 58),
        array( 6, 34, 62),
        array( 6, 26, 46, 66),
        array( 6, 26, 48, 70),
        array( 6, 26, 50, 74),
        array( 6, 30, 54, 78),
        array( 6, 30, 56, 82),
        array( 6, 30, 58, 86),
        array( 6, 34, 62, 90),
        array( 6, 28, 50, 72, 94),
        array( 6, 26, 50, 74, 98),
        array( 6, 30, 54, 78, 102),
        array( 6, 28, 54, 80, 106),
        array( 6, 32, 58, 84, 110),
        array( 6, 30, 58, 86, 114),
        array( 6, 34, 62, 90, 118),
        array( 6, 26, 50, 74, 98, 122),
        array( 6, 30, 54, 78, 102, 126),
        array( 6, 26, 52, 78, 104, 130),
        array( 6, 30, 56, 82, 108, 134),
        array( 6, 34, 60, 86, 112, 138),
        array( 6, 30, 58, 86, 114, 142),
        array( 6, 34, 62, 90, 118, 146),
        array( 6, 30, 54, 78, 102, 126, 150),
        array( 6, 24, 50, 76, 102, 128, 154),
        array( 6, 28, 54, 80, 106, 132, 158),
        array( 6, 32, 58, 84, 110, 136, 162),
        array( 6, 26, 54, 82, 110, 138, 166),
        array( 6, 30, 58, 86, 114, 142, 170)
    );
    
    // ------------------------------------------------------------------
    
    public static $version_info_pos = array(
        array(170, 0), array(171, 0), array(172, 0),
        array(173, 0), array(174, 0), array(175, 0),
        
        array(0, 170), array(0, 171), array(0, 172),
        array(0, 173), array(0, 174), array(0, 175),

        array(170, 5), array(171, 5), array(172, 5),
        array(173, 5), array(174, 5), array(175, 5),
        
    );

    // ------------------------------------------------------------------
    
    public static $format_info_pos = array(
        array( 8,  0), array( 8,  1), array( 8,  2),
        array( 8,  3), array( 8,  4), array( 8,  5),
        array( 8,  7), array( 8,  8),
        array( 7,  8), array( 5,  8), array( 4,  8),
        array( 3,  8), array( 2,  8), array( 1,  8),
        array( 0,  8)
    );
}

// ####################################################################
// ####################################################################
/*
 * Masking class
 */
 
class QRmask
{
    public static function getMask(QRcode $qr, $level, $spec)
    {
        if(QR_FIND_BEST_MASK) {
            $min_penalty = PHP_INT_MAX;
            $best_mask = -1;
            
            for($i=0; $i<8; $i++) {
            
                $qr_temp = clone $qr;
                $qr_temp->data = QRmask::writeFormatInformation($qr_temp, $level, $i);
                $qr_temp->data = QRmask::maskData($qr_temp, $i);
                
                QRspec::putSource($qr_temp, $spec);
                $penalty = self::getPenalty($qr_temp);
                
                if($penalty < $min_penalty) {
                    $min_penalty = $penalty;
                    $best_mask = $i;
                }
            }
            
            return $best_mask;
        }
        return QR_DEFAULT_MASK;
    }
    
    // ------------------------------------------------------------------
    
    public static function writeFormatInformation(QRcode $qr, $level, $mask)
    {
        $bits = self::getFormatInfo($qr->version, $level, $mask);

        for($i=0; $i<15; $i++) {
        
            $bit = ($bits >> $i) & 1;
            
            list($y, $x) = QRspec::$format_info_pos[$i];
            
            $qr->data[$y][$x] = $bit;
            
            if($i < 8)
                $qr->data[$qr->width - 1 - $i][8] = $bit;
            else
                $qr->data[8][$qr->width - 1 - $i + 1] = $bit;
        }

        if($qr->version > 6) {
        
            $bits = self::getVersionInfo($qr->version);
            
            for($i=0; $i<18; $i++) {
                $bit = ($bits >> $i) & 1;
                
                list($y, $x) = QRspec::$version_info_pos[$i];
                $qr->data[$y][$x] = $bit;
                $qr->data[$x][$y] = $bit;
            }
        }
        
        return $qr->data;
    }

    // ------------------------------------------------------------------
    
    public static function maskData(QRcode $qr, $mask)
    {
        $width = $qr->width;
        
        for($y=0; $y<$width; $y++) {
            for($x=0; $x<$width; $x++) {
                if(!($qr->data[$y][$x] & 0x80)) {
                    $qr->data[$y][$x] ^= self::getMaskBit($mask, $y, $x);
                }
            }
        }
        
        return $qr->data;
    }
    
    // ------------------------------------------------------------------
    
    public static function getPenalty(QRcode $qr)
    {
        $width = $qr->width;
        $penalty = 0;

        // RULE 1
        for($y=0; $y<$width; $y++) {
        
            $row_pattern = 0;
            $row_pattern_len = 0;
            $row_bit = $qr->data[$y][0] & 1;
            
            for($x=0; $x<$width; $x++) {
                if( ($qr->data[$y][$x] & 1) == $row_bit) {
                    $row_pattern_len++;
                } else {
                    if($row_pattern_len >= 5) {
                        $penalty += 3 + ($row_pattern_len - 5);
                    }
                    $row_bit = $qr->data[$y][$x] & 1;
                    $row_pattern_len = 1;
                }
            }
            if($row_pattern_len >= 5) {
                $penalty += 3 + ($row_pattern_len - 5);
            }
        }
        
        for($x=0; $x<$width; $x++) {
            
            $col_pattern = 0;
            $col_pattern_len = 0;
            $col_bit = $qr->data[0][$x] & 1;
            
            for($y=0; $y<$width; $y++) {
                if( ($qr->data[$y][$x] & 1) == $col_bit) {
                    $col_pattern_len++;
                } else {
                    if($col_pattern_len >= 5) {
                        $penalty += 3 + ($col_pattern_len - 5);
                    }
                    $col_bit = $qr->data[$y][$x] & 1;
                    $col_pattern_len = 1;
                }
            }
            
            if($col_pattern_len >= 5) {
                $penalty += 3 + ($col_pattern_len - 5);
            }
        }

        // RULE 2
        
        for($y=0; $y<$width-1; $y++) {
            for($x=0; $x<$width-1; $x++) {
                $p = $qr->data[$y][$x] & 1;
                if( $p == ($qr->data[$y][$x+1] & 1)
                 && $p == ($qr->data[$y+1][$x] & 1)
                 && $p == ($qr->data[$y+1][$x+1] & 1)
                 ) {
                     $penalty += 3;
                 }
            }
        }
        
        // RULE 3
        
        $pattern1 = array(0,0,0,0,1,0,1,1,1,0,1);
        $pattern2 = array(1,0,1,1,1,0,1,0,0,0,0);
        $len = 11;
        
        for($y=0; $y<$width; $y++) {
            for($x=0; $x<$width - $len; $x++) {
                $match1 = true;
                $match2 = true;
                for($i=0; $i<$len; $i++) {
                    if( ($qr->data[$y][$x+$i] & 1) != $pattern1[$i] ) {
                        $match1 = false;
                    }
                    if( ($qr->data[$y][$x+$i] & 1) != $pattern2[$i] ) {
                        $match2 = false;
                    }
                }
                
                if($match1 || $match2) $penalty += 40;
            }
        }
        
        for($x=0; $x<$width; $x++) {
            for($y=0; $y<$width - $len; $y++) {
                $match1 = true;
                $match2 = true;
                for($i=0; $i<$len; $i++) {
                    if( ($qr->data[$y+$i][$x] & 1) != $pattern1[$i] ) {
                        $match1 = false;
                    }
                    if( ($qr->data[$y+$i][$x] & 1) != $pattern2[$i] ) {
                        $match2 = false;
                    }
                }
                
                if($match1 || $match2) $penalty += 40;
            }
        }
        
        // RULE 4
        
        $black = 0;
        for($y=0; $y<$width; $y++) {
            for($x=0; $x<$width; $x++) {
                if($qr->data[$y][$x] & 1)
                    $black++;
            }
        }
        
        $total = $width * $width;
        
        $penalty += (int)(abs($black * 20 / $total - 10)) * 10;
        
        return $penalty;
    }
    
    // ------------------------------------------------------------------
    
    public static function getMaskBit($mask, $y, $x)
    {
        switch($mask) {
            case 0: return ($y+$x)%2 == 0;
            case 1: return $y%2 == 0;
            case 2: return $x%3 == 0;
            case 3: return ($y+$x)%3 == 0;
            case 4: return (floor($y/2)+floor($x/3))%2 == 0;
            case 5: return ($y*$x)%2 + ($y*$x)%3 == 0;
            case 6: return (($y*$x)%2 + ($y*$x)%3)%2 == 0;
            case 7: return (($y*$x)%3 + ($y+$x)%2)%2 == 0;
            
            default: return 0;
        }
    }
    
    // ------------------------------------------------------------------
    
    public static function getFormatInfo($version, $level, $mask)
    {
        $levels = array(1,0,3,2);
        
        $format = ($levels[$level] << 3) | $mask;
        
        $bits = $format << 10;
        
        $g = 0x537;
        
        for($i=0; $i<4; $i++) {
            if($bits & (1 << (14-$i))) {
                $bits ^= $g << (4-$i);
            }
        }

        $bch = $bits | (($format << 10) ^ $bits);
        
        return ($bch ^ 0x5412);
    }
    
    // ------------------------------------------------------------------
    
    public static function getVersionInfo($version)
    {
        if($version < 7)
            return 0;
            
        $bits = $version << 12;
        
        $g = 0x1f25;
        
        for($i=0; $i<5; $i++) {
            if($bits & (1 << (17-$i))) {
                $bits ^= $g << (5-$i);
            }
        }
        
        return ($version << 12) | $bits;
    }
}

// ####################################################################
// ####################################################################
/*
 * Input data class
 */
 
class QRinput
{
    public $data;
    public $version;
    public $mode;
    public $casesensitive;

    // ------------------------------------------------------------------
    
    public function __construct($version, $mode, $casesensitive = true)
    {
        if( $version < 0 || $version > QRSPEC_VERSION_MAX || $mode > QR_MODE_KANJI)
             throw new Exception('Invalid QR code parameters.');
            
        $this->data = array();
        $this->version = $version;
        $this->mode = $mode;
        $this->casesensitive = $casesensitive;
    }
    
    // ------------------------------------------------------------------
    
    public function append($text, $size)
    {
        switch($this->mode) {
            case QR_MODE_NUM:
                $this->data[] = new QRinputItem($this->mode, $text, $size);
            break;
            case QR_MODE_AN:
                $this->data[] = new QRinputItem($this->mode, $text, $size);
            break;
            case QR_MODE_8:
                $this->data[] = new QRinputItem($this->mode, $text, $size);
            break;
            default:
                return NULL;
        }
        
        return 0;
    }
    
    // ------------------------------------------------------------------
    
    public function getBitstream()
    {
        $bits = '';
        foreach($this->data as $item) {
            $b = $item->getBitstream($this->version);
            if(is_null($b)) return NULL;
            $bits .= $b;
        }
        
        $words = QRspec::$capacity[$this->version][QRCAP_WORDS];
        $w = $words << 3;

        if(strlen($bits) > $w) {
            return NULL;
        }
        
        if(strlen($bits) < $w) {
            $bits .= str_repeat('0', 4);
        }
        
        return $bits;
    }
    
    // ------------------------------------------------------------------
    
    public static function estimateBitWidth($version, $mode, $size, $casesensitive)
    {
        $bits = 0;
        
        $entry = new QRinputItem($mode, '1', $size);
        if(!is_null($entry)) {
            $bits = $entry->estimateBitWidth($version);
        }

        return $bits;
    }
}
 
// ####################################################################
// ####################################################################
/*
 * Input data item
 */
 
class QRinputItem
{
    public $mode;
    public $data;
    public $size;
    
    // ------------------------------------------------------------------
    
    public function __construct($mode, $data, $size)
    {
        if($mode > QR_MODE_8) 
            throw new Exception('Invalid QR input item mode.');
        
        $this->mode = $mode;
        $this->data = $data;
        $this->size = $size;
    }
    
    // ------------------------------------------------------------------
    
    public function getBitstream($version)
    {
        $bits = '';
        
        $bits .= self::getModeIndicator($this->mode);
        $bits .= self::getLengthIndicator($this->mode, $version, $this->size);
        $bits .= self::getData($this->mode, $this->data, $this->size);
        
        return $bits;
    }
    
    // ------------------------------------------------------------------
    
    public function estimateBitWidth($version)
    {
        $bits = 0;
        
        $bits += self::getModeIndicatorLen();
        $bits += self::getLengthIndicatorLen($this->mode, $version);
        $bits += self::getDataLen($this->mode, $this->size);
        
        return $bits;
    }
    
    // ------------------------------------------------------------------
    
    public static function getModeIndicator($mode)
    {
        $indicators = array('0001','0010','0100','1000');
        return $indicators[$mode];
    }

    // ------------------------------------------------------------------
    
    public static function getLengthIndicator($mode, $version, $size)
    {
        $len = self::getLengthIndicatorLen($mode, $version);
        $bits = decbin($size);
        return str_repeat('0', $len - strlen($bits)).$bits;
    }
    
    // ------------------------------------------------------------------
    
    public static function getData($mode, $data, $size)
    {
        $bits = '';
        
        switch($mode) {
        
            case QR_MODE_NUM:
                $i=0;
                while($i+3 <= $size) {
                    $s = substr($data, $i, 3);
                    $b = decbin((int)$s);
                    $bits .= str_repeat('0', 10 - strlen($b)).$b;
                    $i+=3;
                }
                if($i < $size) {
                    $s = substr($data, $i);
                    $b = decbin((int)$s);
                    if($size-$i == 2)
                        $bits .= str_repeat('0', 7 - strlen($b)).$b;
                    else
                        $bits .= str_repeat('0', 4 - strlen($b)).$b;
                }
            break;
            case QR_MODE_AN:
                $map = array(
                    '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                    'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
                    'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
                    ' ', '$', '%', '*', '+', '-', '.', '/', ':'
                );
                
                $i=0;
                while($i+2 <= $size) {
                    $s = substr($data, $i, 2);
                    $s1 = array_search($s[0], $map);
                    $s2 = array_search($s[1], $map);
                    
                    $b = decbin($s1 * 45 + $s2);
                    $bits .= str_repeat('0', 11-strlen($b)).$b;
                    $i+=2;
                }
                
                if($i < $size) {
                    $s = substr($data, $i, 1);
                    $s1 = array_search($s, $map);
                    $b = decbin($s1);
                    $bits .= str_repeat('0', 6-strlen($b)).$b;
                }
                
            break;
            case QR_MODE_8:
                for($i=0; $i<$size; $i++) {
                    $b = decbin(ord($data[$i]));
                    $bits .= str_repeat('0', 8-strlen($b)).$b;
                }
            break;
            case QR_MODE_KANJI:
            
            break;
            default:
            
            break;
        }
        
        return $bits;
    }

    // ------------------------------------------------------------------
    
    public static function getModeIndicatorLen()
    {
        return 4;
    }
    
    // ------------------------------------------------------------------
    
    public static function getLengthIndicatorLen($mode, $version)
    {
        if ($version <= 9)
            $v = 0;
        else if ($version <= 26)
            $v = 1;
        else
            $v = 2;
            
        return self::$length_table[$mode][$v];
    }
    
    // ------------------------------------------------------------------
    
    public static function getDataLen($mode, $size)
    {
        $ret = 0;
        switch($mode) {
            case QR_MODE_NUM: $ret = (int)($size/3)*10 + (($size%3)?(($size%3)*3+1):0); break;
            case QR_MODE_AN:  $ret = (int)($size/2)*11 + ($size%2)*6; break;
            case QR_MODE_8:   $ret = $size * 8; break;
        }
        
        return $ret;
    }

    // ------------------------------------------------------------------
    
    public static $length_table = array(
        array(10, 12, 14),
        array(9, 11, 13),
        array(8, 16, 16),
        array(8, 10, 12)
    );
}

// ####################################################################
// ####################################################################
/*
 * Reed-Solomon Error Correction
 */
 
class QRrs
{
    // ------------------------------------------------------------------
    
    public static function getEccTables($level)
    {
        return self::$ecc_tables[$level];
    }
    
    // ------------------------------------------------------------------
    
    public static function encode($spec, $version, $level, $tab)
    {
        $rs = new QRrsItem($spec, $version, $level, $tab);
        return $rs->getEcc();
    }
    
    // ------------------------------------------------------------------
    
    public static $ecc_tables = array(
        // L
        array(
            array(0,0),
            array( 1, 0,  19,  1,   7,  1),
            array( 1, 0,  34,  1,  10,  1),
            array( 1, 0,  55,  1,  15,  1),
            array( 1, 0,  80,  1,  20,  1),
            array( 1, 0, 108,  1,  26,  1),
            array( 2, 0,  68,  2,  18,  2),
            array( 2, 0,  78,  2,  20,  2),
            array( 2, 0,  97,  2,  24,  2),
            array( 2, 0, 116,  2,  30,  2),
            array( 4, 0,  43,  2,  15,  2,   2,  16),
            array( 4, 0,  58,  2,  18,  2,   2,  19),
            array( 4, 0,  64,  2,  22,  2,   2,  23),
            array( 4, 0,  72,  4,  26,  1),
            array( 4, 0,  86,  3,  15,  1,   1,  16),
            array( 4, 0,  98,  2,  18,  2,   2,  19),
            array( 4, 0,  87,  4,  19,  1,   2,  20),
            array( 4, 0,  98,  4,  22,  1,   2,  23),
            array( 4, 0, 107,  2,  24,  2,   4,  25),
            array( 4, 0, 120,  4,  27,  1,   2,  28),
            array( 4, 0, 115,  2,  22,  1,   6,  23),
            array( 4, 0, 115,  3,  22,  1,   4,  23),
            array( 4, 0, 121,  4,  24,  1,   4,  25),
            array( 4, 0, 135,  2,  27,  2,   6,  28),
            array( 6, 0,  75,  2,  22,  2,   2,  23),
            array( 6, 0,  81,  2,  24,  2,   4,  25),
            array( 6, 0,  90,  4,  27,  1,   4,  28),
            array( 6, 0, 102,  4,  30,  1,   4,  31),
            array( 6, 0, 107,  1,  22,  1,   5,  23,  1,  24),
            array( 6, 0, 115,  4,  24,  2,   2,  25,  4,  26),
            array( 6, 0, 107,  2,  22,  2,   2,  23,  2,  24),
            array( 6, 0, 111,  6,  24,  2,   2,  25),
            array( 8, 0,  92,  2,  22,  2,   4,  23,  2,  24),
            array( 8, 0,  98,  6,  24,  2,   2,  25),
            array( 8, 0, 107,  8,  25,  1,   4,  26),
            array( 8, 0, 121,  4,  28,  4,   4,  29),
            array( 8, 0, 114,  3,  24,  1,  13,  25),
            array( 8, 0, 115,  2,  24,  4,   4,  25,  4,  26),
            array( 8, 0, 115,  4,  24,  2,   4,  25,  4,  26),
            array( 10, 0,115,  4,  24,  2,   6,  25,  2,  26),
            array( 10, 0,115,  4,  24,  1,   9,  25,  3,  26)
        ),
        // M
        array(
            array(0,0),
            array( 1, 0,  16,  1,  10,  1),
            array( 1, 0,  28,  1,  16,  1),
            array( 1, 0,  44,  1,  26,  1),
            array( 2, 0,  32,  2,  18,  2),
            array( 2, 0,  43,  2,  24,  2),
            array( 4, 0,  27,  4,  15,  1),
            array( 4, 0,  34,  4,  18,  1),
            array( 4, 0,  38,  4,  22,  1),
            array( 4, 0,  46,  4,  26,  1),
            array( 4, 0,  55,  2,  15,  2,   2,  16),
            array( 4, 0,  68,  4,  18,  1),
            array( 4, 0,  81,  2,  22,  2,   2,  23),
            array( 4, 0,  92,  4,  26,  1),
            array( 6, 0,  43,  2,  15,  2,   2,  16),
            array( 6, 0,  50,  2,  18,  2,   2,  19),
            array( 6, 0,  58,  4,  19,  1,   2,  20),
            array( 6, 0,  64,  4,  22,  1,   2,  23),
            array( 6, 0,  74,  4,  24,  1,   2,  25),
            array( 6, 0,  82,  4,  27,  1,   2,  28),
            array( 8, 0,  50,  4,  22,  2),
            array( 8, 0,  58,  3,  22,  1,   4,  23),
            array( 8, 0,  64,  4,  24,  1,   4,  25),
            array( 8, 0,  70,  4,  27,  2),
            array( 8, 0,  75,  4,  22,  2,   4,  23),
            array( 10, 0, 81,  2,  24,  2,   6,  25),
            array( 12, 0, 90,  2,  27,  2,   8,  28),
            array( 12, 0, 96,  4,  30,  4),
            array( 12, 0,  92,  2,  22,  3,   4,  23,  4,  24),
            array( 12, 0, 102,  4,  24,  4,   2,  25,  4,  26),
            array( 12, 0, 107,  2,  22,  2,   2,  23,  6,  24),
            array( 12, 0, 111,  6,  24,  2,   2,  25),
            array( 14, 0,  92,  2,  22,  2,   4,  23,  4,  24),
            array( 16, 0,  98,  6,  24,  2,   4,  25),
            array( 16, 0, 107,  4,  25,  6,   4,  26),
            array( 16, 0, 108,  6,  28,  2,   4,  29),
            array( 16, 0, 114,  3,  24,  1,   8,  25,  4,  26),
            array( 16, 0, 115,  2,  24,  4,   4,  25,  6,  26),
            array( 16, 0, 115,  4,  24,  2,   4,  25,  6,  26),
            array( 18, 0, 115,  4,  24,  2,   7,  25,  4,  26),
            array( 18, 0, 115,  4,  24,  5,   5,  25,  4,  26),
        ),
        // Q
        array(
            array(0,0),
            array( 1, 0,  13,  1,  13,  1),
            array( 1, 0,  22,  1,  22,  1),
            array( 2, 0,  17,  2,  34,  1),
            array( 2, 0,  24,  2,  44,  1),
            array( 4, 0,  15,  4,  30,  1),
            array( 4, 0,  20,  2,  40,  2),
            array( 4, 0,  24,  4,  48,  1),
            array( 4, 0,  28,  4,  56,  1),
            array( 4, 0,  35,  4,  70,  1),
            array( 6, 0,  21,  2,  42,  2,   2,  43),
            array( 6, 0,  26,  4,  52,  1),
            array( 6, 0,  30,  3,  60,  1,   1,  61),
            array( 6, 0,  34,  2,  68,  2,   2,  69),
            array( 6, 0,  38,  4,  76,  1,   1,  77),
            array( 6, 0,  43,  2,  86,  2,   2,  87),
            array( 8, 0,  33,  4,  66,  2),
            array( 8, 0,  38,  4,  76,  2),
            array( 8, 0,  42,  2,  84,  4),
            array( 10, 0, 48,  4,  96,  2),
            array( 10, 0, 43,  2,  86,  2,   6,  87),
            array( 12, 0, 43,  3,  86,  1,   8,  87),
            array( 12, 0, 47,  4,  94,  4),
            array( 12, 0, 54,  2, 108,  6),
            array( 14, 0, 51,  4, 102,  4),
            array( 16, 0, 54,  2, 108,  2,   8, 109),
            array( 16, 0, 57,  4, 114,  4),
            array( 16, 0, 60,  4, 120,  4),
            array( 18, 0,  50,  3, 100,  1,   6, 101,  2, 102),
            array( 18, 0,  54,  4, 108,  4,   2, 109,  4, 110),
            array( 20, 0,  54,  2, 108,  2,   4, 109,  6, 110),
            array( 20, 0,  57,  6, 114,  2,   4, 115),
            array( 22, 0,  54,  2, 108,  2,   5, 109,  4, 110),
            array( 24, 0,  57,  6, 114,  2,   6, 115),
            array( 24, 0,  57,  8, 114,  1,   6, 115),
            array( 26, 0,  57,  4, 114,  4,   6, 115),
            array( 26, 0,  57,  3, 114,  1,  11, 115),
            array( 28, 0,  57,  2, 114,  4,   6, 115,  4, 116),
            array( 28, 0,  57,  4, 114,  2,   6, 115,  6, 116),
            array( 28, 0,  57,  4, 114,  2,   8, 115,  4, 116),
            array( 28, 0,  57,  4, 114,  1,  10, 115,  4, 116),
        ),
        // H
        array(
            array(0,0),
            array( 1, 0,   9,  1,  17,  1),
            array( 1, 0,  16,  1,  28,  1),
            array( 2, 0,  13,  2,  36,  1),
            array( 2, 0,  18,  2,  48,  1),
            array( 2, 0,  26,  2,  64,  1),
            array( 4, 0,  15,  4,  40,  1),
            array( 4, 0,  18,  4,  48,  1),
            array( 4, 0,  22,  4,  60,  1),
            array( 4, 0,  26,  4,  72,  1),
            array( 6, 0,  18,  2,  42,  2,   2,  43),
            array( 6, 0,  22,  2,  52,  2,   2,  53),
            array( 6, 0,  26,  4,  60,  1),
            array( 8, 0,  22,  4,  60,  2),
            array( 8, 0,  24,  3,  64,  1,   1,  65),
            array( 8, 0,  28,  4,  72,  2),
            array( 8, 0,  30,  4,  80,  2),
            array( 10, 0, 28,  4,  76,  1,   2,  77),
            array( 10, 0, 30,  2,  84,  2,   4,  85),
            array( 12, 0, 30,  4,  86,  1,   4,  87),
            array( 12, 0, 30,  2,  82,  1,   6,  83),
            array( 14, 0, 30,  3,  86,  1,   4,  87),
            array( 16, 0, 30,  4,  90,  4),
            array( 16, 0, 30,  2,  96,  6),
            array( 16, 0, 30,  4,  92,  2,   4,  93),
            array( 16, 0, 30,  2, 100,  2,   8, 101),
            array( 18, 0, 30,  4, 108,  4),
            array( 18, 0, 30,  4, 114,  4),
            array( 20, 0, 30,  3, 100,  1,   6, 101,  2, 102),
            array( 20, 0, 30,  4, 104,  4,   2, 105,  4, 106),
            array( 22, 0, 30,  2, 104,  2,   4, 105,  6, 106),
            array( 22, 0, 30,  6, 108,  2,   4, 109),
            array( 24, 0, 30,  2, 100,  2,   5, 101,  4, 102),
            array( 24, 0, 30,  6, 104,  2,   6, 105),
            array( 26, 0, 30,  8, 110,  1,   6, 111),
            array( 26, 0, 30,  4, 112,  4,   6, 113),
            array( 28, 0, 30,  3, 108,  1,   8, 109,  4, 110),
            array( 28, 0, 30,  2, 108,  4,   6, 109,  6, 110),
            array( 28, 0, 30,  4, 112,  2,   6, 113,  6, 114),
            array( 28, 0, 30,  4, 112,  2,   8, 113,  4, 114),
            array( 28, 0, 30,  4, 112,  1,  10, 113,  4, 114),
        )
    );
}

// ####################################################################
// ####################################################################
/*
 * Reed-Solomon Error Correction - Item
 */

class QRrsItem
{
    public $spec;
    public $poly;

    // ------------------------------------------------------------------
    
    public function __construct($spec, $version, $level, $tab)
    {
        $this->spec = $spec;
        
        $rs = $tab[$version];
        
        $this->poly = array();
        
        for($i=0; $i<$rs[0]; $i++) {
            $this->poly[] = new QRrsPoly($rs[$i*3+1], $rs[$i*3+2]);
        }
    }
    
    // ------------------------------------------------------------------
    
    public function getEcc()
    {
        $ecc = '';
        
        foreach($this->poly as $poly) {
            $ecc .= $poly->getEcc($this->spec);
        }
        
        return $ecc;
    }
}
 
// ####################################################################
// ####################################################################
/*
 * Reed-Solomon Error Correction - Polynomial
 */

class QRrsPoly
{
    public $size;
    public $poly;
    
    public $gexp = array();
    public $glog = array();
    
    // ------------------------------------------------------------------
    
    public function __construct($size, $poly)
    {
        $this->size = $size;
        
        $this->init_galois();
        $this->poly = $this->gen_poly($size, $poly);
    }
    
    // ------------------------------------------------------------------
    
    public function init_galois()
    {
        $this->gexp = array_fill(0, 512, 0);
        $this->glog = array_fill(0, 256, 0);
        
        $p = 1;
        for($i=0; $i<255; $i++) {
            $this->gexp[$i] = $p;
            $this->glog[$p] = $i;
            $p = $p << 1;
            if($p & 0x100)
                $p ^= 0x11d;
        }
        for($i=255; $i<512; $i++) {
            $this->gexp[$i] = $this->gexp[$i - 255];
        }
    }
    
    // ------------------------------------------------------------------
    
    public function gen_poly($size, $poly)
    {
        $p = array_fill(0, $size+1, 0);
        
        $p[0] = 1;
        
        for($i=1; $i<=$size; $i++) {
            $p[$i] = 1;
            for($j=$i-1; $j>0; $j--) {
                $p[$j] = $this->gmult($p[$j], $this->gexp[$i-1+$poly]) ^ $p[$j-1];
            }
            $p[0] = $this->gmult($p[0], $this->gexp[$i-1+$poly]);
        }

        return $p;
    }
    
    // ------------------------------------------------------------------
    
    public function gmult($a, $b)
    {
        if(!$a || !$b) return 0;
        
        return $this->gexp[$this->glog[$a] + $this->glog[$b]];
    }
    
    // ------------------------------------------------------------------
    
    public function getEcc($spec)
    {
        $msg = array_fill(0, $this->size, 0);
        $msg = array_merge($msg, unpack("C*", $spec));
        
        $len = count($msg);
        
        for($i=0; $i<$len - $this->size; $i++) {
            $coef = $msg[$i];
            if($coef != 0) {
                for($j=1; $j<=$this->size; $j++) {
                    $msg[$i+$j] ^= $this->gmult($this->poly[$j], $coef);
                }
            }
        }
        
        $code = '';
        for($i=0; $i<$this->size; $i++) {
            $code .= pack('C', $msg[$len - $this->size + $i]);
        }
        
        return $code;
    }
