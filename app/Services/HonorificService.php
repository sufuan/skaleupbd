<?php

namespace App\Services;

/**
 * How the AI should address a customer: আপু, ভাইয়া, or no word at all.
 *
 * The shop picks the mode in Settings → AI & Auto Reply (ai.behavior
 * 'honorific'): 'apu' / 'bhaiya' force one word for everyone, 'none'
 * never uses one, and 'auto' (the default) guesses from the customer's
 * name — common Bangladeshi name parts are strongly gendered (Akter,
 * Begum, Khatun → female; Uddin, Mia, Ullah → male). When auto cannot
 * tell, the reply uses NO honorific rather than guessing wrong.
 */
class HonorificService
{
    /** Surname/title parts that mark a woman (Roman spellings). */
    private const FEMALE_MARKERS = [
        'akter', 'aktar', 'khatun', 'begum', 'sultana', 'parvin', 'parveen',
        'nahar', 'rani', 'kumari', 'devi', 'bibi', 'banu', 'nesa', 'ara',
    ];

    /** Surname/title parts that mark a man (Roman spellings). */
    private const MALE_MARKERS = ['uddin', 'mia', 'miah', 'ullah'];

    /** Common female first names (Roman spellings). */
    private const FEMALE_NAMES = [
        'fatema', 'fatima', 'ayesha', 'aisha', 'khadija', 'maryam', 'mariam',
        'zainab', 'hafsa', 'sumaiya', 'sharmin', 'nasrin', 'nusrat', 'farhana',
        'farzana', 'sabrina', 'sabina', 'taslima', 'tahmina', 'ruma', 'rumana',
        'shilpi', 'lipi', 'popy', 'moushumi', 'shabnur', 'purnima', 'rina',
        'mina', 'tina', 'nina', 'riya', 'priya', 'puja', 'anika', 'tanisha',
        'mim', 'meem', 'nabila', 'fariha', 'faria', 'sadia', 'nadia', 'tanzila',
        'jannat', 'jannatul', 'ishrat', 'israt', 'sharifa', 'salma', 'selina',
        'shahana', 'shahida', 'rokeya', 'roksana', 'rahima', 'karima', 'halima',
        'amina', 'amena', 'sakina', 'rubina', 'yasmin', 'jesmin', 'jasmine',
        'nasima', 'hosneara', 'dilara', 'kohinoor', 'kohinur', 'morjina',
        'marjina', 'tasnia', 'anisha', 'arifa', 'asifa', 'latifa', 'sharmeen',
        'nazma', 'naznin', 'nilufa', 'shahnaz', 'ferdousi', 'bilkis', 'momtaz',
        'mumtaz', 'shamsunnahar', 'kamrunnahar', 'mahmuda', 'mahfuza', 'masuda',
        'rashida', 'wahida', 'fahima', 'fahmida', 'naima', 'saima', 'mehjabin',
        'mehzabin', 'sadika', 'afrin', 'afreen', 'afsana', 'humayra', 'humaira',
        'sumona', 'monira', 'munira', 'jobeda', 'jubaida', 'kulsum', 'kulsom',
        'aklima', 'aleya', 'rita', 'gita', 'sita', 'anita', 'sunita', 'sabita',
        'kabita', 'babita', 'namita', 'mamata', 'manisha', 'priyanka', 'deepika',
        'dipika', 'ananya', 'anonna', 'aparna', 'shraboni', 'srabonti', 'bithi',
        'mithi', 'juthi', 'jui', 'keya', 'nila', 'neela', 'laila', 'layla',
        'leila', 'shayla', 'shaila', 'umme', 'ummey', 'sharika', 'rifah', 'rifa',
        'tania', 'taniya', 'sonia', 'soniya', 'rabia', 'rabeya', 'habiba',
        'shakila', 'shirin', 'sherin', 'dilruba', 'rokhsana', 'shamsun',
        'kamrun', 'mursheda', 'mahbuba', 'mahbooba', 'sajeda', 'sajida',
        'majeda', 'hamida', 'zarina', 'zarin', 'farida',
    ];

    /** Common male first names (Roman spellings). */
    private const MALE_NAMES = [
        'rahim', 'karim', 'jamal', 'kamal', 'abdul', 'abdullah', 'mohammad',
        'mohammed', 'muhammad', 'ahmed', 'ahmad', 'hasan', 'hassan', 'hossain',
        'hossen', 'hussain', 'ali', 'akbar', 'asad', 'asif', 'arif', 'anis',
        'anisur', 'anwar', 'anowar', 'ashraf', 'ashraful', 'azad', 'aziz',
        'azim', 'babul', 'badal', 'bakul', 'belal', 'bilal', 'billal', 'delwar',
        'dilwar', 'dulal', 'elias', 'ilias', 'emran', 'imran', 'enamul',
        'ershad', 'irshad', 'farid', 'farhad', 'farhan', 'faroque', 'faruk',
        'faruq', 'faisal', 'faysal', 'fazal', 'fazle', 'ferdous', 'firoz',
        'feroz', 'fuad', 'gias', 'giyas', 'golam', 'habib', 'habibul', 'hafiz',
        'hafizur', 'haider', 'hayder', 'hakim', 'halim', 'hamid', 'hanif',
        'haris', 'harun', 'haroon', 'hasib', 'hasin', 'hasnat', 'helal',
        'hilal', 'hridoy', 'ridoy', 'humayun', 'ibrahim', 'idris', 'iftikhar',
        'ikram', 'ilham', 'imad', 'imdad', 'imtiaz', 'imtiyaz', 'iqbal',
        'ikbal', 'irfan', 'ishak', 'ishaq', 'ismail', 'ismael', 'istiaque',
        'ishtiaque', 'jafar', 'jafor', 'jahangir', 'jahid', 'jahir', 'jalal',
        'jalil', 'jaman', 'jamil', 'jashim', 'jasim', 'jewel', 'jibon', 'jiban',
        'jihad', 'jishan', 'jisan', 'jitu', 'jobair', 'jobayer', 'jobaer',
        'jony', 'joni', 'joy', 'joynal', 'jubair', 'jubayer', 'julhas', 'jumman',
        'junayed', 'juned', 'kafi', 'kaiser', 'kaysar', 'kajol', 'kalam',
        'kalim', 'kamrul', 'kamruzzaman', 'kanak', 'kanchan', 'kapil',
        'karimul', 'kashem', 'kasem', 'kausar', 'kawsar', 'khaled', 'khalid',
        'khalil', 'khalilur', 'khokon', 'khorshed', 'khurshid', 'kibria',
        'kiran', 'kiron', 'kishore', 'kishor', 'kuddus', 'kudrat', 'kumar',
        'kunal', 'kurban', 'kutub', 'kutubuddin', 'mahmud', 'mahmood', 'masud',
        'masood', 'mamun', 'mamoon', 'manik', 'mannan', 'mansur', 'mansoor',
        'maqsud', 'maqsood', 'maruf', 'maroof', 'mizan', 'mizanur', 'moazzem',
        'moazzam', 'mobarak', 'mobarok', 'mofiz', 'mofizur', 'mohiuddin',
        'mohsin', 'mohibul', 'mohibullah', 'nadim', 'nadeem', 'naeem', 'naim',
        'naimul', 'naimur', 'najib', 'najibul', 'najibullah', 'najmul', 'najim',
        'najem', 'najam', 'nakib', 'nafis', 'nabil', 'nabeel', 'nabid',
        'nabhan', 'obaidul', 'obaidullah', 'obaidulla', 'obaid', 'obayed',
        'obaidur', 'rafi', 'rafid', 'rafiq', 'rafiqul', 'rafiqur', 'rafiul',
        'rafiuddin', 'rafiullah', 'rafiulla',
        'raihan', 'raihanul', 'raihanur', 'rais', 'raisul', 'raisur',
        'raisuddin', 'raisullah', 'raisulla', 'raisal', 'raisan', 'raisar',
        'raiz', 'raizul', 'raizur', 'tanvir', 'tanveer', 'sumon', 'rubel',
        'sohel', 'shakib', 'tamim', 'monir', 'nurul',
    ];

    /** Female name parts written in Bangla script (names + markers). */
    private const FEMALE_NAMES_BN = [
        'ফাতেমা', 'আয়েশা', 'খাদিজা', 'মরিয়ম', 'সুমাইয়া', 'শারমিন', 'নাসরিন',
        'নুসরাত', 'ফারহানা', 'সাবরিনা', 'তাসলিমা', 'রহিমা', 'করিমা', 'হালিমা',
        'আমিনা', 'সালমা', 'শাহানা', 'রোকেয়া', 'ইয়াসমিন', 'পারভিন', 'শিরিন',
        'নাসিমা', 'জান্নাত', 'মাহমুদা', 'মাসুদা', 'রাশিদা', 'ফাহিমা', 'নাইমা',
        'সাইমা', 'আফরিন', 'আফসানা', 'হুমায়রা', 'মনিরা', 'মুনিরা', 'জোবেদা',
        'কুলসুম', 'রানী', 'দেবী', 'কুমারী', 'প্রিয়া', 'পূজা', 'রিয়া', 'অনিকা',
        'সাদিয়া', 'নাদিয়া', 'ফারিয়া', 'ফারিহা', 'নাবিলা', 'মিম', 'তানিয়া',
        'সোনিয়া', 'রাবেয়া', 'হাবিবা', 'শাকিলা', 'দিলরুবা', 'মাহবুবা',
        'সাজেদা', 'হামিদা', 'লতিফা', 'জারিন', 'ফরিদা', 'ওয়াহিদা',
        'আক্তার', 'আখতার', 'বেগম', 'খাতুন', 'সুলতানা', 'নাহার', 'বিবি', 'বানু', 'নেসা', 'আরা',
    ];

    /** Male name parts written in Bangla script (names + markers). */
    private const MALE_NAMES_BN = [
        'রহিম', 'করিম', 'জামাল', 'কামাল', 'আব্দুল', 'মোহাম্মদ', 'আহমেদ',
        'হাসান', 'হোসেন', 'আলী', 'আসিফ', 'আরিফ', 'আনিস', 'আনোয়ার', 'আশরাফ',
        'আজিজ', 'বাবুল', 'বিলাল', 'দেলোয়ার', 'দুলাল', 'ইমরান', 'ফরিদ',
        'ফারুক', 'ফয়সাল', 'ফিরোজ', 'হাবিব', 'হাফিজ', 'হাকিম', 'হালিম',
        'হামিদ', 'হানিফ', 'হারুন', 'হাসিব', 'হেলাল', 'হুমায়ুন', 'ইব্রাহিম',
        'ইসমাইল', 'ইকবাল', 'ইরফান', 'জাফর', 'জাহাঙ্গীর', 'জাহিদ', 'জালাল',
        'জলিল', 'জামিল', 'জসিম', 'জুয়েল', 'জীবন', 'জিহাদ', 'জয়', 'জুনায়েদ',
        'খালেদ', 'খালিল', 'খোকন', 'খোরশেদ', 'কিবরিয়া', 'কিরণ', 'কিশোর',
        'কুদ্দুস', 'কুমার', 'মাহমুদ', 'মাসুদ', 'মামুন', 'মানিক', 'মান্নান',
        'মানসুর', 'মারুফ', 'মিজান', 'মোয়াজ্জেম', 'মোবারক', 'মহিউদ্দিন',
        'মহসিন', 'নাদিম', 'নাঈম', 'নাজিব', 'নাজমুল', 'নাকিব', 'নাফিস',
        'নাবিল', 'ওবায়দুল', 'ওবায়দুল্লাহ', 'রফি', 'রফিদ', 'রফিক', 'রফিউল',
        'রায়হান', 'রাইস', 'রাইজুল', 'তানভির', 'তানভীর', 'সুমন',
        'উদ্দিন', 'মিয়া', 'মিঞা', 'উল্লাহ',
    ];

    /** Placeholder sender names Meta gives us when the real name is hidden. */
    private const PLACEHOLDER_NAMES = [
        'messenger user', 'facebook user', 'instagram user', 'whatsapp user',
        'unknown', 'unknown user', 'customer', 'guest', 'user',
    ];

    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * The honorific for this customer: 'আপু', 'ভাইয়া' or '' (no word).
     * The shop's saved mode wins; 'auto' (and anything unset) infers from
     * the name and stays silent when it cannot tell.
     */
    public function forName(int $businessId, ?string $customerName): string
    {
        $behavior = $this->settings->get('ai.behavior', []);
        $mode = is_array($behavior) ? (string) ($behavior['honorific'] ?? 'auto') : 'auto';

        return match ($mode) {
            'apu' => 'আপু',
            'bhaiya' => 'ভাইয়া',
            'none' => '',
            default => match ($this->genderForName($customerName)) {
                'f' => 'আপু',
                'm' => 'ভাইয়া',
                default => '',
            },
        };
    }

    /**
     * Guess 'f' / 'm' from a customer name, or null when the name gives no
     * reliable signal. Tokens are scanned left to right and the FIRST
     * decisive token wins — in "Sharmin Akter" the first name decides long
     * before the surname marker is even needed.
     */
    public function genderForName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) < 2) {
            return null;
        }
        if (in_array(mb_strtolower($name), self::PLACEHOLDER_NAMES, true)) {
            return null;
        }
        // Split on anything that is not a letter or a combining mark —
        // Bangla vowel signs are marks (\p{M}), not letters, so a plain
        // [^\p{L}] split would shatter Bangla words into single letters.
        $tokens = preg_split('/[^\p{L}\p{M}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            $t = mb_strtolower($token);
            if (in_array($t, self::FEMALE_MARKERS, true) || in_array($t, self::FEMALE_NAMES, true) || in_array($t, self::FEMALE_NAMES_BN, true)) {
                return 'f';
            }
            if (in_array($t, self::MALE_MARKERS, true) || in_array($t, self::MALE_NAMES, true) || in_array($t, self::MALE_NAMES_BN, true)) {
                return 'm';
            }
        }

        return null;
    }
}
